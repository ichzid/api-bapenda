<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Sts;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RealisasiController extends Controller
{
    /**
     * Get Realisasi Pajak Daerah Realtime
     */
    public function getRealisasi(Request $request)
    {
        $tahun = $request->input('tahun', Carbon::now()->year);
        $cacheKey = "realisasi_overview_v3_{$tahun}";

        try {
            $payload = Cache::remember($cacheKey, 5, function () use ($tahun) {
                $jenisPajak = $this->getJenisPajakMapping();
                $targetData = $this->getTargets();

                // Fetch raw realization data from all sources
                $stsData = $this->fetchMainRealisasi($tahun, array_keys($jenisPajak));
                $pbbRealisasi = $this->fetchDataPBB($tahun);
                $opsenData = $this->fetchDataOpsen();

                $totalTargetKeseluruhan = 0;
                $totalRealisasiKeseluruhan = 0;

                // Process and merge data — kembalikan array biasa, bukan Collection/Response
                $formattedData = [];
                foreach ($jenisPajak as $jenisAsli => $namaBaru) {
                    $realisasi = 0;
                    if ($jenisAsli === 'PBB') {
                        $realisasi = (float) $pbbRealisasi;
                    } elseif ($jenisAsli === 'Opsen PKB') {
                        $realisasi = (float) ($opsenData['pkb'] ?? 0);
                    } elseif ($jenisAsli === 'Opsen BBNKB') {
                        $realisasi = (float) ($opsenData['bbnkb'] ?? 0);
                    } else {
                        $item = $stsData->firstWhere('Jn_Pajak', $jenisAsli);
                        $realisasi = $item ? (float) $item->total_realisasi : 0;
                    }

                    $target = (float) ($targetData[$jenisAsli] ?? 0);
                    $selisih = $realisasi - $target;
                    $persentase = $target > 0 ? ($realisasi / $target) * 100 : 0;
                    $detailTersedia = $this->isDetailAvailableForJenis($jenisAsli);

                    $totalTargetKeseluruhan += $target;
                    $totalRealisasiKeseluruhan += $realisasi;

                    $formattedData[] = [
                        'jenis_pajak'       => $namaBaru,
                        'kode_jenis_pajak'  => $jenisAsli,
                        'slug_jenis_pajak'  => Str::slug($jenisAsli),
                        'sumber_data'       => $this->resolveSumberData($jenisAsli),
                        'detail_tersedia'   => $detailTersedia,
                        'detail_endpoint'   => $detailTersedia
                            ? '/api/realisasi-pajak/' . Str::slug($jenisAsli) . '/detail'
                            : null,
                        'target'            => $target,
                        'realisasi'         => $realisasi,
                        'persentase'        => round($persentase, 2),
                        'selisih'           => $selisih,
                    ];
                }

                $persentaseGlobal = $totalTargetKeseluruhan > 0
                    ? ($totalRealisasiKeseluruhan / $totalTargetKeseluruhan) * 100
                    : 0;

                return [
                    'success'   => true,
                    'message'   => 'Data ringkasan realisasi & target berhasil diambil.',
                    'tahun'     => (int) $tahun,
                    'ringkasan' => [
                        'total_target'       => $totalTargetKeseluruhan,
                        'total_realisasi'    => $totalRealisasiKeseluruhan,
                        'persentase_capaian' => round($persentaseGlobal, 2),
                        'selisih_anggaran'   => $totalRealisasiKeseluruhan - $totalTargetKeseluruhan,
                    ],
                    'rincian'   => $formattedData,
                ];
            });

            return response()->json($payload);
        } catch (\Exception $e) {
            Cache::forget($cacheKey);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get ringkasan realisasi Retribusi Daerah.
     */
    public function getRealisasiRetribusi(Request $request)
    {
        $tahun = $request->integer('tahun', Carbon::now()->year);
        $cacheKey = "realisasi_retribusi_overview_v2_{$tahun}";

        try {
            $payload = Cache::remember($cacheKey, 5, function () use ($tahun) {
                $totalTarget = 0;
                $totalRealisasi = 0;
                $rincian = [];

                foreach ($this->fetchRealisasiRetribusi($tahun) as $item) {
                    $target = (float) $item['target'];
                    $realisasi = (float) $item['realisasi'];
                    $persentase = $target > 0 ? ($realisasi / $target) * 100 : 0;

                    $totalTarget += $target;
                    $totalRealisasi += $realisasi;

                    $rincian[] = [
                        'jenis_retribusi'      => $item['jenis_retribusi'],
                        'nama_opd'             => $item['nama_opd'],
                        'slug_jenis_retribusi' => Str::slug($item['jenis_retribusi']),
                        'kelompok_retribusi'   => $item['kelompok_retribusi'],
                        'sumber_data'          => 'sts_history+e_retribusi',
                        'detail_tersedia'      => false,
                        'detail_endpoint'      => null,
                        'target'               => $target,
                        'realisasi'            => $realisasi,
                        'persentase'           => round($persentase, 2),
                        'selisih'              => $realisasi - $target,
                    ];
                }

                $persentaseGlobal = $totalTarget > 0
                    ? ($totalRealisasi / $totalTarget) * 100
                    : 0;

                return [
                    'success'   => true,
                    'message'   => 'Data ringkasan realisasi & target retribusi berhasil diambil.',
                    'tahun'     => $tahun,
                    'ringkasan' => [
                        'total_target'       => $totalTarget,
                        'total_realisasi'    => $totalRealisasi,
                        'persentase_capaian' => round($persentaseGlobal, 2),
                        'selisih_anggaran'   => $totalRealisasi - $totalTarget,
                    ],
                    'rincian' => $rincian,
                ];
            });

            return response()->json($payload);
        } catch (\Exception $e) {
            Cache::forget($cacheKey);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data retribusi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get tren penerimaan bulanan pajak atau retribusi.
     */
    public function getTrenPenerimaan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tahun' => ['required', 'integer'],
            'jenis' => ['required', 'in:pajak,retribusi'],
            'kode' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter request tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tahun = (int) $request->input('tahun');
        $jenis = $request->input('jenis');
        $kode = $request->filled('kode') ? trim((string) $request->input('kode')) : null;

        if ($kode !== null && $kode === '') {
            $kode = null;
        }

        $jenisPajak = $this->getJenisPajakMapping();
        if ($jenis === 'pajak' && in_array($kode, $this->getUnsupportedDetailJenis(), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Opsen PKB dan Opsen BBNKB tidak tersedia pada tren penerimaan.',
            ], 422);
        }

        if ($jenis === 'pajak' && $kode !== null && ! array_key_exists($kode, $jenisPajak)) {
            return response()->json([
                'success' => false,
                'message' => 'Kode jenis pajak tidak ditemukan atau tidak sesuai kelompok pajak.',
            ], 404);
        }

        $targetRetribusi = null;
        if ($jenis === 'retribusi' && $kode !== null) {
            if (! ctype_digit($kode) || ! array_key_exists((int) $kode, $this->getRetribusiTargets($tahun))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode jenis retribusi tidak ditemukan atau tidak sesuai kelompok retribusi untuk tahun tersebut.',
                ], 404);
            }

            $targetRetribusi = $this->getRetribusiTargets($tahun)[(int) $kode];
        }

        $cacheKey = 'tren_penerimaan_v1_' . md5(json_encode([
            'tahun' => $tahun,
            'jenis' => $jenis,
            'kode' => $kode,
        ]));

        try {
            $payload = Cache::remember($cacheKey, 5, function () use ($tahun, $jenis, $kode, $jenisPajak, $targetRetribusi) {
                $realisasiPerBulan = array_fill(1, 12, 0.0);
                $nama = null;

                if ($jenis === 'pajak') {
                    $kodeSts = array_values(array_diff(
                        array_keys($jenisPajak),
                        ['PBB', 'Opsen PKB', 'Opsen BBNKB']
                    ));

                    if ($kode === null || $kode !== 'PBB') {
                        $query = Sts::query()
                            ->selectRaw('MONTH(Tgl_Bayar) as bulan, SUM(Nilai) as total_realisasi')
                            ->where('Status_Bayar', 1)
                            ->where('Tgl_Bayar', '>=', "{$tahun}-01-01")
                            ->where('Tgl_Bayar', '<', ($tahun + 1) . '-01-01')
                            ->whereIn('Jn_Pajak', $kode === null ? $kodeSts : [$kode])
                            ->groupByRaw('MONTH(Tgl_Bayar)')
                            ->get();

                        foreach ($query as $item) {
                            $realisasiPerBulan[(int) $item->bulan] += (float) $item->total_realisasi;
                        }
                    }

                    if ($kode === null || $kode === 'PBB') {
                        $pbb = DB::connection('epbb_db')
                            ->table('pembayaran_sppt')
                            ->selectRaw('MONTH(tgl_rekam_byr_sppt) as bulan, SUM(jml_sppt_yg_dibayar) as total_realisasi')
                            ->where('tgl_rekam_byr_sppt', '>=', "{$tahun}-01-01")
                            ->where('tgl_rekam_byr_sppt', '<', ($tahun + 1) . '-01-01')
                            ->groupByRaw('MONTH(tgl_rekam_byr_sppt)')
                            ->get();

                        foreach ($pbb as $item) {
                            $realisasiPerBulan[(int) $item->bulan] += (float) $item->total_realisasi;
                        }
                    }

                    $nama = $kode !== null ? $jenisPajak[$kode] : null;
                } elseif ($kode === null) {
                    $rows = Sts::query()
                        ->selectRaw('MONTH(Tgl_Bayar) as bulan, SUM(Nilai) as total_realisasi')
                        ->where('Jn_Pajak', 'Retribusi')
                        ->where('Status_Bayar', 1)
                        ->where('Tgl_Bayar', '>=', "{$tahun}-01-01")
                        ->where('Tgl_Bayar', '<', ($tahun + 1) . '-01-01')
                        ->groupByRaw('MONTH(Tgl_Bayar)')
                        ->get();

                    foreach ($rows as $item) {
                        $realisasiPerBulan[(int) $item->bulan] = (float) $item->total_realisasi;
                    }
                } else {
                    $sourceIds = $targetRetribusi['sumber_id_jenis'] ?? [(int) $kode];
                    $pembayaran = Sts::query()
                        ->select('No_NOP')
                        ->selectRaw('MONTH(Tgl_Bayar) as bulan, SUM(Nilai) as total_realisasi')
                        ->where('Jn_Pajak', 'Retribusi')
                        ->where('Status_Bayar', 1)
                        ->where('Tgl_Bayar', '>=', "{$tahun}-01-01")
                        ->where('Tgl_Bayar', '<', ($tahun + 1) . '-01-01')
                        ->groupBy('No_NOP')
                        ->groupByRaw('MONTH(Tgl_Bayar)')
                        ->get();
                    $formNumbers = $pembayaran->pluck('No_NOP')
                        ->filter(fn ($number) => $number !== null && $number !== '')
                        ->unique()
                        ->values();

                    if ($formNumbers->isNotEmpty()) {
                        $paymentsByForm = $pembayaran->groupBy(fn ($item) => (string) $item->No_NOP);
                        DB::connection('eretribusi_db')
                            ->table('lap_skrd')
                            ->whereIn('no_form', $formNumbers->all())
                            ->whereIn('idretribusi', $sourceIds)
                            ->select('no_form')
                            ->orderBy('no_form')
                            ->chunk(1000, function ($forms) use ($paymentsByForm, &$realisasiPerBulan) {
                                foreach ($forms as $form) {
                                    foreach ($paymentsByForm->get((string) $form->no_form, collect()) as $payment) {
                                        $realisasiPerBulan[(int) $payment->bulan] += (float) $payment->total_realisasi;
                                    }
                                }
                            });
                    }

                    $master = DB::connection('eretribusi_db')
                        ->table('jenis_retribusi')
                        ->where('id_jenis', (int) $kode)
                        ->value('nama_retribusi');
                    $nama = trim((string) ($master ?? $targetRetribusi['jenis_retribusi']));
                }

                $namaBulan = [
                    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
                ];
                $rincian = [];
                foreach ($namaBulan as $bulan => $label) {
                    $rincian[] = [
                        'bulan' => $bulan,
                        'nama_bulan' => $label,
                        'realisasi' => (float) $realisasiPerBulan[$bulan],
                    ];
                }

                return [
                    'success' => true,
                    'tahun' => $tahun,
                    'jenis' => $jenis,
                    'kode' => $kode,
                    'nama' => $nama,
                    'ringkasan' => [
                        'total_realisasi' => (float) array_sum($realisasiPerBulan),
                    ],
                    'rincian' => $rincian,
                ];
            });

            return response()->json($payload);
        } catch (\Exception $e) {
            Cache::forget($cacheKey);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil tren penerimaan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detail realisasi untuk satu jenis pajak dari STS History.
     */
    public function getRealisasiDetail(Request $request, string $jenisPajak)
    {
        $tahun = $request->input('tahun', Carbon::now()->year);
        $page = max($request->integer('page', 1), 1);
        $perPage = min(max($request->integer('per_page', $request->integer('limit', 10)), 1), 50);

        $jenisPajakMapping = $this->getJenisPajakMapping();
        $jenisAsli = $this->resolveJenisPajak($jenisPajak, $jenisPajakMapping);

        if (! $jenisAsli) {
            return response()->json([
                'success' => false,
                'message' => 'Jenis pajak tidak ditemukan.',
            ], 404);
        }

        if (! $this->isDetailAvailableForJenis($jenisAsli)) {
            return response()->json([
                'success' => false,
                'message' => 'Detail untuk jenis pajak ini belum tersedia.',
            ], 422);
        }

        if ($jenisAsli === 'PBB') {
            return $this->getDetailPBB($tahun, $page, $perPage, $jenisAsli, $jenisPajakMapping);
        }

        try {
            $target = $this->getTargets()[$jenisAsli] ?? 0;

            // Ringkasan & top pembayar di-cache (jarang berubah, mahal dihitung)
            $cacheKey = "detail_ringkasan_{$jenisAsli}_{$tahun}";
            $cachedRingkasan = Cache::remember($cacheKey, 30, function () use ($tahun, $jenisAsli, $target) {
                $query = $this->buildStsDetailQuery($tahun, $jenisAsli);

                // Gabung SUM + COUNT dalam 1 query (hemat 1x full scan)
                $ringkasan = (clone $query)->select(
                    DB::raw('SUM(Nilai) as total_realisasi'),
                    DB::raw('COUNT(*) as total_transaksi')
                )->first();

                $realisasi = (float) ($ringkasan->total_realisasi ?? 0);
                $jumlahTransaksi = (int) ($ringkasan->total_transaksi ?? 0);
                $persentase = $target > 0 ? ($realisasi / $target) * 100 : 0;

                return [
                    'realisasi' => $realisasi,
                    'jumlah_transaksi' => $jumlahTransaksi,
                    'persentase_capaian' => round($persentase, 2),
                    'sisa_anggaran' => $target - $realisasi,
                ];
            });

            $realisasi = $cachedRingkasan['realisasi'];
            $jumlahTransaksi = $cachedRingkasan['jumlah_transaksi'];

            // Pagination (tidak di-cache karena per halaman berbeda)
            $query = $this->buildStsDetailQuery($tahun, $jenisAsli);
            $pembayaranPaginator = (clone $query)
                ->select([
                    'No_STS',
                    'No_NOP',
                    'No_Pokok_WP',
                    'Nama_Pemilik',
                    'Alamat_Pemilik',
                    'Nilai',
                    'Denda',
                    'Tgl_Bayar',
                    'Kode_Pengesahan',
                    'Kode_Cab',
                    'Nama_Channel',
                ])
                ->orderByDesc('Tgl_Bayar')
                ->orderByDesc('No_STS')
                ->paginate($perPage, ['*'], 'page', $page);

            $pembayaranData = collect($pembayaranPaginator->items())
                ->map(fn ($item) => $this->formatPembayaranItem($item))
                ->values();

            // Top pembayar dari cache
            $topPembayarCacheKey = "detail_top_{$jenisAsli}_{$tahun}";
            $topPembayar = Cache::remember($topPembayarCacheKey, 30, function () use ($tahun, $jenisAsli) {
                $topQuery = $this->buildStsDetailQuery($tahun, $jenisAsli);
                return $this->fetchTopPembayar($topQuery, $jenisAsli);
            });

            return response()->json([
                'success' => true,
                'message' => 'Detail realisasi pajak berhasil diambil.',
                'data' => [
                    'jenis_pajak' => [
                        'kode' => $jenisAsli,
                        'slug' => Str::slug($jenisAsli),
                        'nama' => $jenisPajakMapping[$jenisAsli],
                        'sumber_data' => 'sts_history',
                    ],
                    'ringkasan' => [
                        'target' => $target,
                        'realisasi' => $realisasi,
                        'persentase_capaian' => $cachedRingkasan['persentase_capaian'],
                        'sisa_anggaran' => $cachedRingkasan['sisa_anggaran'],
                        'jumlah_transaksi' => $jumlahTransaksi,
                    ],
                    'top_pembayar' => $topPembayar,
                    'pembayaran' => [
                        'data' => $pembayaranData,
                        'pagination' => [
                            'current_page' => $pembayaranPaginator->currentPage(),
                            'per_page' => $pembayaranPaginator->perPage(),
                            'total' => $pembayaranPaginator->total(),
                            'last_page' => $pembayaranPaginator->lastPage(),
                            'from' => $pembayaranPaginator->firstItem(),
                            'to' => $pembayaranPaginator->lastItem(),
                            'has_more_pages' => $pembayaranPaginator->hasMorePages(),
                        ],
                        'sorting' => [
                            'sort_by' => 'Tgl_Bayar',
                            'sort_direction' => 'desc',
                        ],
                    ],
                ],
                'filters' => [
                    'tahun' => (int) $tahun,
                    'page' => $pembayaranPaginator->currentPage(),
                    'per_page' => $pembayaranPaginator->perPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detail realisasi untuk PBB dari epbb_db
     */
    private function getDetailPBB($tahun, $page, $perPage, $jenisAsli, $jenisPajakMapping)
    {
        try {
            $target = $this->getTargets()[$jenisAsli] ?? 0;

            // Ringkasan di-cache
            $cacheKey = "pbb_detail_ringkasan_v4_{$tahun}";
            $cachedRingkasan = Cache::remember($cacheKey, 30, function () use ($tahun, $target) {
                $baseQuery = DB::connection('epbb_db')
                    ->table('pembayaran_sppt as p')
                    ->where('p.tgl_rekam_byr_sppt', '>=', "{$tahun}-01-01")
                    ->where('p.tgl_rekam_byr_sppt', '<', ($tahun + 1) . '-01-01');

                $ringkasan = (clone $baseQuery)->select(
                    DB::raw('SUM(jml_sppt_yg_dibayar) as total_realisasi'),
                    DB::raw('COUNT(*) as total_transaksi')
                )->first();

                $realisasi = (float) ($ringkasan->total_realisasi ?? 0);
                $jumlahTransaksi = (int) ($ringkasan->total_transaksi ?? 0);
                $persentase = $target > 0 ? ($realisasi / $target) * 100 : 0;

                return [
                    'realisasi' => $realisasi,
                    'jumlah_transaksi' => $jumlahTransaksi,
                    'persentase_capaian' => round($persentase, 2),
                    'sisa_anggaran' => $target - $realisasi,
                ];
            });

            $realisasi = $cachedRingkasan['realisasi'];
            $jumlahTransaksi = $cachedRingkasan['jumlah_transaksi'];

            // Pagination — pakai LIMIT+OFFSET (tanpa COUNT(*))
            $offset = ($page - 1) * $perPage;
            $baseQuery = DB::connection('epbb_db')
                ->table('pembayaran_sppt as p')
                ->where('p.tgl_rekam_byr_sppt', '>=', "{$tahun}-01-01")
                ->where('p.tgl_rekam_byr_sppt', '<', ($tahun + 1) . '-01-01');

            $rows = (clone $baseQuery)
                ->select([
                    'p.kd_propinsi', 'p.kd_dati2', 'p.kd_kecamatan', 'p.kd_kelurahan',
                    'p.kd_blok', 'p.no_urut', 'p.kd_jns_op',
                    'p.jml_sppt_yg_dibayar as total_bayar',
                    'p.denda_sppt as denda',
                    'p.tgl_rekam_byr_sppt as Tgl_Bayar',
                    'p.nip_rekam_byr_sppt as channel',
                ])
                ->orderByDesc('p.tgl_rekam_byr_sppt')
                ->offset($offset)
                ->limit($perPage + 1) // +1 untuk cek has_more
                ->get();

            $hasMore = $rows->count() > $perPage;
            $pembayaranItems = $rows->take($perPage);

            // Bulk lookup nama WP — pakai raw SQL WHERE IN (tuple) jauh lebih cepat
            $spptNames = [];
            if ($pembayaranItems->isNotEmpty()) {
                $values = [];
                $bindings = [];
                foreach ($pembayaranItems as $item) {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                    array_push($bindings,
                        $item->kd_propinsi, $item->kd_dati2, $item->kd_kecamatan,
                        $item->kd_kelurahan, $item->kd_blok, $item->no_urut, $item->kd_jns_op
                    );
                }
                $sql = "SELECT kd_propinsi, kd_dati2, kd_kecamatan, kd_kelurahan, kd_blok, no_urut, kd_jns_op, nm_wp_sppt FROM sppt WHERE (kd_propinsi, kd_dati2, kd_kecamatan, kd_kelurahan, kd_blok, no_urut, kd_jns_op) IN (" . implode(',', $values) . ')';
                $spptData = DB::connection('epbb_db')->select($sql, $bindings);
                foreach ($spptData as $sppt) {
                    $key = "{$sppt->kd_propinsi}.{$sppt->kd_dati2}.{$sppt->kd_kecamatan}.{$sppt->kd_kelurahan}.{$sppt->kd_blok}.{$sppt->no_urut}.{$sppt->kd_jns_op}";
                    $spptNames[$key] = $sppt->nm_wp_sppt;
                }
            }

            // Format data
            $pembayaranData = $pembayaranItems->map(function ($item) use ($spptNames) {
                $nop = "{$item->kd_propinsi}.{$item->kd_dati2}.{$item->kd_kecamatan}.{$item->kd_kelurahan}.{$item->kd_blok}.{$item->no_urut}.{$item->kd_jns_op}";
                $denda = (float) $item->denda;
                $totalBayar = (float) $item->total_bayar;
                $nilaiPokok = $totalBayar - $denda;

                return [
                    'no_sts' => '-',
                    'no_nop' => $nop,
                    'no_pokok_wp' => null,
                    'nama_pemilik' => $spptNames[$nop] ?? '-',
                    'alamat_pemilik' => null,
                    'nilai' => $nilaiPokok,
                    'denda' => $denda,
                    'total_bayar' => $totalBayar,
                    'tgl_bayar' => $item->Tgl_Bayar,
                    'kode_pengesahan' => null,
                    'kode_cab' => null,
                    'nama_channel' => $item->channel ?? null,
                ];
            })->values();

            // Top Pembayar (di-cache)
            $topCacheKey = "pbb_detail_top_v3_{$tahun}";
            $topPembayar = Cache::remember($topCacheKey, 30, function () use ($tahun) {
                $topPembayarRaw = DB::connection('epbb_db')
                    ->table('pembayaran_sppt as p')
                    ->where('p.tgl_rekam_byr_sppt', '>=', "{$tahun}-01-01")
                    ->where('p.tgl_rekam_byr_sppt', '<', ($tahun + 1) . '-01-01')
                    ->select([
                        'p.kd_propinsi', 'p.kd_dati2', 'p.kd_kecamatan', 'p.kd_kelurahan',
                        'p.kd_blok', 'p.no_urut', 'p.kd_jns_op',
                        DB::raw('SUM(p.jml_sppt_yg_dibayar - p.denda_sppt) as total_nilai'),
                        DB::raw('SUM(p.denda_sppt) as total_denda'),
                        DB::raw('SUM(p.jml_sppt_yg_dibayar) as total_bayar'),
                        DB::raw('COUNT(*) as jumlah_transaksi'),
                    ])
                    ->groupBy('p.kd_propinsi', 'p.kd_dati2', 'p.kd_kecamatan', 'p.kd_kelurahan', 'p.kd_blok', 'p.no_urut', 'p.kd_jns_op')
                    ->orderByDesc('total_bayar')
                    ->limit(10)
                    ->get();

                $topSpptNames = [];
                if ($topPembayarRaw->isNotEmpty()) {
                    $values = [];
                    $bindings = [];
                    foreach ($topPembayarRaw as $item) {
                        $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                        array_push($bindings,
                            $item->kd_propinsi, $item->kd_dati2, $item->kd_kecamatan,
                            $item->kd_kelurahan, $item->kd_blok, $item->no_urut, $item->kd_jns_op
                        );
                    }
                    $sql = "SELECT kd_propinsi, kd_dati2, kd_kecamatan, kd_kelurahan, kd_blok, no_urut, kd_jns_op, nm_wp_sppt FROM sppt WHERE (kd_propinsi, kd_dati2, kd_kecamatan, kd_kelurahan, kd_blok, no_urut, kd_jns_op) IN (" . implode(',', $values) . ')';
                    $spptTopData = DB::connection('epbb_db')->select($sql, $bindings);
                    foreach ($spptTopData as $sppt) {
                        $key = "{$sppt->kd_propinsi}.{$sppt->kd_dati2}.{$sppt->kd_kecamatan}.{$sppt->kd_kelurahan}.{$sppt->kd_blok}.{$sppt->no_urut}.{$sppt->kd_jns_op}";
                        $topSpptNames[$key] = $sppt->nm_wp_sppt;
                    }
                }

                return $topPembayarRaw->map(function ($row) use ($topSpptNames) {
                    $nop = "{$row->kd_propinsi}.{$row->kd_dati2}.{$row->kd_kecamatan}.{$row->kd_kelurahan}.{$row->kd_blok}.{$row->no_urut}.{$row->kd_jns_op}";
                    return [
                        'no_pokok_wp'      => $nop,
                        'nama_pemilik'     => $topSpptNames[$nop] ?? 'NN',
                        'total_nilai'      => (float) $row->total_nilai,
                        'total_denda'      => (float) $row->total_denda,
                        'total_bayar'      => (float) $row->total_bayar,
                        'jumlah_transaksi' => (int) $row->jumlah_transaksi,
                    ];
                })->values()->all();
            });

            return response()->json([
                'success' => true,
                'message' => 'Detail realisasi pajak berhasil diambil.',
                'data' => [
                    'jenis_pajak' => [
                        'kode' => $jenisAsli,
                        'slug' => Str::slug($jenisAsli),
                        'nama' => $jenisPajakMapping[$jenisAsli],
                        'sumber_data' => 'epbb_db',
                    ],
                    'ringkasan' => [
                        'target' => $target,
                        'realisasi' => $realisasi,
                        'persentase_capaian' => $cachedRingkasan['persentase_capaian'],
                        'sisa_anggaran' => $cachedRingkasan['sisa_anggaran'],
                        'jumlah_transaksi' => $jumlahTransaksi,
                    ],
                    'top_pembayar' => $topPembayar,
                    'pembayaran' => [
                        'data' => $pembayaranData,
                        'pagination' => [
                            'current_page' => $page,
                            'per_page' => $perPage,
                            'total' => $jumlahTransaksi,
                            'last_page' => (int) ceil($jumlahTransaksi / $perPage),
                            'has_more_pages' => $hasMore,
                            'from' => $offset + 1,
                            'to' => $offset + $pembayaranItems->count(),
                        ],
                        'sorting' => [
                            'sort_by' => 'Tgl_Bayar',
                            'sort_direction' => 'desc',
                        ],
                    ],
                ],
                'filters' => [
                    'tahun' => (int) $tahun,
                    'page' => $page,
                    'per_page' => $perPage,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data PBB: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mengambil data utama dari tabel STS History
     */
    private function fetchMainRealisasi($tahun, array $jenisPajakKeys)
    {
        return Sts::select('Jn_Pajak', DB::raw('SUM(Nilai) as total_realisasi'))
            ->whereIn('Jn_Pajak', $jenisPajakKeys)
            ->where('Status_Bayar', 1)
            ->whereYear('Tgl_Bayar', $tahun)
            ->groupBy('Jn_Pajak')
            ->get();
    }

    /**
     * Query dasar detail pembayaran STS untuk satu jenis pajak.
     */
    private function buildStsDetailQuery($tahun, string $jenisPajak)
    {
        $query = Sts::query()
            ->where('Jn_Pajak', $jenisPajak)
            ->where('Status_Bayar', 1)
            ->whereYear('Tgl_Bayar', $tahun);

        if ($jenisPajak === 'BPHTB') {
            $query->where('Nilai', '>', 0);
        }

        return $query;
    }

    /**
     * Mengambil 10 pembayar dengan total akumulasi pembayaran tertinggi
     * dalam satu jenis pajak dan tahun tertentu.
     * Jika Retribusi, group by Nama_Pemilik, selain itu per No_Pokok_WP.
     */
    private function fetchTopPembayar($query, string $jenisAsli): array
    {
        $selectFields = [
            'Nama_Pemilik',
            DB::raw('SUM(Nilai) as total_nilai'),
            DB::raw('SUM(Denda) as total_denda'),
            DB::raw('SUM(Nilai + Denda) as total_bayar'),
            DB::raw('COUNT(*) as jumlah_transaksi'),
        ];

        $groupByFields = ['Nama_Pemilik'];

        // Jika bukan retribusi, sertakan juga No_Pokok_WP
        if ($jenisAsli !== 'Retribusi') {
            array_unshift($selectFields, 'No_Pokok_WP');
            array_unshift($groupByFields, 'No_Pokok_WP');
        }

        $rows = (clone $query)
            ->select($selectFields)
            ->groupBy($groupByFields)
            ->orderByDesc('total_bayar')
            ->limit(10)
            ->get();

        return $rows->map(fn ($row) => [
            'no_pokok_wp'      => $jenisAsli !== 'Retribusi' ? $row->No_Pokok_WP : null,
            'nama_pemilik'     => $row->Nama_Pemilik,
            'total_nilai'      => (float) $row->total_nilai,
            'total_denda'      => (float) $row->total_denda,
            'total_bayar'      => (float) $row->total_bayar,
            'jumlah_transaksi' => (int) $row->jumlah_transaksi,
        ])->values()->all();
    }

    /**
     * Format item pembayaran agar konsisten untuk frontend.
     */
    private function formatPembayaranItem(object $item): array
    {
        $nilai = (float) $item->Nilai;
        $denda = (float) $item->Denda;

        return [
            'no_sts' => $item->No_STS,
            'no_nop' => $item->No_NOP,
            'no_pokok_wp' => $item->No_Pokok_WP,
            'nama_pemilik' => $item->Nama_Pemilik,
            'alamat_pemilik' => $item->Alamat_Pemilik,
            'nilai' => $nilai,
            'denda' => $denda,
            'total_bayar' => $nilai + $denda,
            'tgl_bayar' => $item->Tgl_Bayar,
            'kode_pengesahan' => $item->Kode_Pengesahan,
            'kode_cab' => $item->Kode_Cab,
            'nama_channel' => $item->Nama_Channel,
        ];
    }

    /**
     * Mengambil data khusus PBB dari epbb_db
     */
    private function fetchDataPBB($tahun): float
    {
        try {
            $pbbData = DB::connection('epbb_db')
                ->table('pembayaran_sppt')
                ->where('tgl_rekam_byr_sppt', '>=', "{$tahun}-01-01")
                ->where('tgl_rekam_byr_sppt', '<', ($tahun + 1) . '-01-01')
                ->select(DB::raw('SUM(jml_sppt_yg_dibayar) AS total_realisasi'))
                ->first();
                
            return $pbbData && $pbbData->total_realisasi ? (float) $pbbData->total_realisasi : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Mengambil data Opsen PKB & BBNKB dari simpada
     */
    private function fetchDataOpsen(): array
    {
        try {
            $opsenData = DB::connection('simpada')
                ->table('realisasi_opsen')
                ->where('id', 1)
                ->select('pkb', 'bbnkb')
                ->first();
                
            if ($opsenData) {
                return [
                    'pkb'   => (float) $opsenData->pkb,
                    'bbnkb' => (float) $opsenData->bbnkb
                ];
            }
        } catch (\Exception $e) {
            // Ignored
        }

        return ['pkb' => 0, 'bbnkb' => 0];
    }

    /**
     * Konfigurasi mapping pajak (Nama Asli -> Nama Dashboard)
     */
    private function getJenisPajakMapping(): array
    {
        return [
            'PBB' => 'Pajak Bumi dan Bangunan',
            'BPHTB' => 'Pajak BPHTB',
            'Pajak Restoran' => 'PBJT Atas Makanan dan Minuman',
            'Pajak Penerangan' => 'PBJT Atas Tenaga Listrik',
            'Pajak Hotel' => 'PBJT Atas Jasa Perhotelan',
            'Pajak Parkir' => 'PBJT Atas Jasa Parkir',
            'Pajak Hiburan' => 'PBJT Atas Jasa Kesenian dan Hiburan',
            'Pajak Reklame' => 'Pajak Reklame',
            'Pajak Air Tanah' => 'Pajak Air Tanah (PAT)',
            'Pajak Galian' => 'Pajak Mineral Bukan Logam dan Batuan (MBLB)',
            'Pajak Sarang Burung Walet' => 'Pajak Sarang Burung Walet',
            'Opsen PKB' => 'Opsen PKB',
            'Opsen BBNKB' => 'Opsen BBNKB',
        ];
    }

    /**
     * Jenis pajak yang detail-nya belum dilayani oleh endpoint STS.
     */
    private function getUnsupportedDetailJenis(): array
    {
        return ['Opsen PKB', 'Opsen BBNKB'];
    }

    /**
     * Menentukan apakah detail tersedia dari sumber STS.
     */
    private function isDetailAvailableForJenis(string $jenisPajak): bool
    {
        return ! in_array($jenisPajak, $this->getUnsupportedDetailJenis(), true);
    }

    /**
     * Resolve jenis pajak dari slug atau nama aslinya.
     */
    private function resolveJenisPajak(string $jenisPajak, array $jenisPajakMapping): ?string
    {
        if (array_key_exists($jenisPajak, $jenisPajakMapping)) {
            return $jenisPajak;
        }

        foreach (array_keys($jenisPajakMapping) as $jenisAsli) {
            if (Str::slug($jenisAsli) === $jenisPajak) {
                return $jenisAsli;
            }
        }

        return null;
    }

    /**
     * Menentukan sumber data utama untuk tiap jenis pajak.
     */
    private function resolveSumberData(string $jenisPajak): string
    {
        return match ($jenisPajak) {
            'PBB' => 'epbb_db',
            'Opsen PKB', 'Opsen BBNKB' => 'simpada',
            default => 'sts_history',
        };
    }

    /**
     * Mengambil realisasi pembayaran dari STS History dan melengkapi jenis/OPD
     * dari database e_retribusi melalui nomor formulir.
     */
    private function fetchRealisasiRetribusi(int $tahun): array
    {
        $pembayaran = Sts::query()
            ->select('No_NOP', DB::raw('SUM(Nilai) as total_realisasi'))
            ->where('Jn_Pajak', 'Retribusi')
            ->where('Status_Bayar', 1)
            ->where('Tgl_Bayar', '>=', "{$tahun}-01-01")
            ->where('Tgl_Bayar', '<', ($tahun + 1) . '-01-01')
            ->groupBy('No_NOP')
            ->get();

        $formNumbers = $pembayaran->pluck('No_NOP')
            ->filter(fn ($number) => $number !== null && $number !== '')
            ->unique()
            ->values();

        $realisasiPerJenis = [];
        if ($formNumbers->isNotEmpty()) {
            DB::connection('eretribusi_db')
                ->table('lap_skrd as l')
                ->whereIn('l.no_form', $formNumbers->all())
                ->select('l.no_form', 'l.idretribusi')
                ->orderBy('l.no_form')
                ->chunk(1000, function ($forms) use ($pembayaran, &$realisasiPerJenis) {
                    $paymentsByForm = $pembayaran->keyBy(fn ($item) => (string) $item->No_NOP);

                    foreach ($forms as $form) {
                        $payment = $paymentsByForm->get((string) $form->no_form);
                        if (! $payment) {
                            continue;
                        }

                        $idJenis = (int) $form->idretribusi;
                        $realisasiPerJenis[$idJenis] = ($realisasiPerJenis[$idJenis] ?? 0)
                            + (float) $payment->total_realisasi;
                    }
                });
        }

        $masters = DB::connection('eretribusi_db')
            ->table('jenis_retribusi as j')
            ->leftJoin('opd_retribusi as o', 'o.id_opd', '=', 'j.opd_id')
            ->select('j.id_jenis', 'j.nama_retribusi', 'o.nama_opd')
            ->get()
            ->keyBy('id_jenis');

        return collect($this->getRetribusiTargets($tahun))
            ->map(function ($target, $idJenis) use ($masters, $realisasiPerJenis) {
                $master = $masters->get($idJenis);

                return [
                    'jenis_retribusi' => trim((string) ($master->nama_retribusi ?? $target['jenis_retribusi'])),
                    'nama_opd' => trim((string) ($master->nama_opd ?? $target['nama_opd'])),
                    'kelompok_retribusi' => $target['kelompok_retribusi'],
                    'target' => $target['target'],
                    'realisasi' => collect($target['sumber_id_jenis'] ?? [$idJenis])
                        ->sum(fn ($sourceId) => $realisasiPerJenis[$sourceId] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Target retribusi berdasarkan laporan REALISASI RETRIBUSI 2026.
     */
    private function getRetribusiTargets(int $tahun): array
    {
        if ($tahun !== 2026) {
            return [];
        }

        return [
            1 => [
                'kelompok_retribusi' => 'Retribusi Jasa Umum',
                'jenis_retribusi' => 'Retribusi Pelayanan Kesehatan - Puskesmas',
                'nama_opd' => 'Dinas Kesehatan Pengendalian Penduduk dan Keluarga Berencana',
                'target' => 1150000000,
            ],
            2 => [
                'kelompok_retribusi' => 'Retribusi Jasa Umum',
                'jenis_retribusi' => 'Retribusi Pelayanan Kesehatan Rumah Sakit Umum',
                'nama_opd' => 'Rumah Sakit Umum',
                'target' => 16510000000,
            ],
            3 => [
                'kelompok_retribusi' => 'Retribusi Jasa Umum',
                'jenis_retribusi' => 'Retribusi Pelayanan Persampahan/Kebersihan',
                'nama_opd' => 'Dinas Perumahan Kawasan Permukiman dan Lingkungan Hidup',
                'target' => 2500000000,
            ],
            4 => [
                'kelompok_retribusi' => 'Retribusi Jasa Umum',
                'jenis_retribusi' => 'Retribusi Pelayanan Parkir di Tepi Jalan Umum',
                'nama_opd' => 'Dinas Perhubungan',
                'target' => 309000000,
            ],
            5 => [
                'kelompok_retribusi' => 'Retribusi Jasa Umum',
                'jenis_retribusi' => 'Retribusi Pelayanan Pasar - Kios',
                'nama_opd' => 'Dinas Ketenagakerjaan Perindustrian dan Perdagangan',
                'target' => 360500000,
            ],
            20 => [
                'kelompok_retribusi' => 'Retribusi Jasa Usaha',
                'jenis_retribusi' => 'Retribusi Pemakaian Kekayaan Daerah - Sewa Alat Berat',
                'nama_opd' => 'Dinas PUPR',
                'target' => 2500000000,
            ],
            21 => [
                'kelompok_retribusi' => 'Retribusi Jasa Usaha',
                'jenis_retribusi' => 'Retribusi Tempat Pelelangan Ikan (TPI)',
                'nama_opd' => 'Dinas Perikanan dan Peternakan',
                'target' => 100000000,
                'sumber_id_jenis' => [21, 27],
            ],
            14 => [
                'kelompok_retribusi' => 'Retribusi Jasa Usaha',
                'jenis_retribusi' => 'Retribusi Tempat Pelelangan Ternak',
                'nama_opd' => 'Dinas Perikanan dan Peternakan',
                'target' => 0,
            ],
            15 => [
                'kelompok_retribusi' => 'Retribusi Jasa Usaha',
                'jenis_retribusi' => 'Retribusi Tempat Rekreasi dan Olahraga',
                'nama_opd' => 'Dinas Pemuda, Olahraga, Kebudayaan dan Pariwisata',
                'target' => 154500000,
            ],
            16 => [
                'kelompok_retribusi' => 'Retribusi Perizinan Tertentu',
                'jenis_retribusi' => 'Retribusi Persetujuan Bangunan Gedung',
                'nama_opd' => 'Dinas Penanaman Modal dan Pelayanan Terpadu Satu Pintu',
                'target' => 1030000000,
            ],
        ];
    }

    /**
     * Data target statis (TODO: Ganti menjadi data dari database jika tersedia)
     */
    private function getTargets(): array
    {
        return [
            'PBB' => 64002000000,
            'BPHTB' => 10500000000,
            'Pajak Restoran' => 4000000000,
            'Pajak Penerangan' => 77265000000,
            'Pajak Hotel' => 350000000,
            'Pajak Parkir' => 150000000,
            'Pajak Hiburan' => 100000000,
            'Pajak Reklame' => 1000000000,
            'Pajak Air Tanah' => 2200000000,
            'Pajak Galian' => 400000000,
            'Pajak Sarang Burung Walet' => 75000000,
            'Opsen PKB' => 10289383518,
            'Opsen BBNKB' => 11714467058,
        ];
    }
}
