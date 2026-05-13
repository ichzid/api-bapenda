<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Sts;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealisasiController extends Controller
{
    /**
     * Get Realisasi Pajak Daerah Realtime
     */
    public function getRealisasi(Request $request)
    {
        $tahun = $request->input('tahun', Carbon::now()->year);

        $jenisPajak = $this->getJenisPajakMapping();
        $targetData = $this->getTargets();

        try {
            // Fetch raw realization data from all sources
            $stsData = $this->fetchMainRealisasi($tahun, array_keys($jenisPajak));
            $pbbRealisasi = $this->fetchDataPBB($tahun);
            $opsenData = $this->fetchDataOpsen();

            $totalTargetKeseluruhan = 0;
            $totalRealisasiKeseluruhan = 0;

            // Process and merge data
            $formattedData = collect($jenisPajak)->map(function ($namaBaru, $jenisAsli) use (
                $stsData, $targetData, $pbbRealisasi, $opsenData, &$totalTargetKeseluruhan, &$totalRealisasiKeseluruhan
            ) {
                // Determine Realisasi
                $realisasi = 0;
                if ($jenisAsli === 'PBB') {
                    $realisasi = $pbbRealisasi;
                } elseif ($jenisAsli === 'Opsen PKB') {
                    $realisasi = $opsenData['pkb'];
                } elseif ($jenisAsli === 'Opsen BBNKB') {
                    $realisasi = $opsenData['bbnkb'];
                } else {
                    $item = $stsData->firstWhere('Jn_Pajak', $jenisAsli);
                    $realisasi = $item ? (float) $item->total_realisasi : 0;
                }

                $target = $targetData[$jenisAsli] ?? 0;
                $selisih = $realisasi - $target;
                $persentase = $target > 0 ? ($realisasi / $target) * 100 : 0;
                $detailTersedia = $this->isDetailAvailableForJenis($jenisAsli);

                // Accumulate totals
                $totalTargetKeseluruhan += $target;
                $totalRealisasiKeseluruhan += $realisasi;

                return [
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
            })->values();

            // Calculate global achievement percentage
            $persentaseGlobal = $totalTargetKeseluruhan > 0 
                ? ($totalRealisasiKeseluruhan / $totalTargetKeseluruhan) * 100 
                : 0;

            return response()->json([
                'success'   => true,
                'message'   => 'Data ringkasan realisasi & target berhasil diambil.',
                'tahun'     => $tahun,
                'ringkasan' => [
                    'total_target'       => $totalTargetKeseluruhan,
                    'total_realisasi'    => $totalRealisasiKeseluruhan,
                    'persentase_capaian' => round($persentaseGlobal, 2),
                    'selisih_anggaran'   => $totalRealisasiKeseluruhan - $totalTargetKeseluruhan
                ],
                'rincian'   => $formattedData,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
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
        $perPage = min(max($request->integer('per_page', $request->integer('limit', 10)), 1), 100);

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

        try {
            $query = $this->buildStsDetailQuery($tahun, $jenisAsli);
            $target = $this->getTargets()[$jenisAsli] ?? 0;
            $realisasi = (float) (clone $query)->sum('Nilai');
            $persentase = $target > 0 ? ($realisasi / $target) * 100 : 0;
            $jumlahTransaksi = (clone $query)->count();

            $pembayaranPaginator = (clone $query)
                ->select([
                    'No_STS',
                    'No_SSPD',
                    'No_Ketetapan',
                    'No_Pokok_WP',
                    'Nama_Pemilik',
                    'Alamat_Pemilik',
                    'Mata_Anggaran',
                    'Nilai',
                    'Denda',
                    'Tgl_Bayar',
                    'Nama_Channel',
                    'Pembayaran_Ke',
                    'kode_pembayaran',
                ])
                ->orderByDesc('Tgl_Bayar')
                ->orderByDesc('No_STS')
                ->paginate($perPage, ['*'], 'page', $page);

            $pembayaranData = collect($pembayaranPaginator->items())
                ->map(fn ($item) => $this->formatPembayaranItem($item))
                ->values();

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
                        'persentase_capaian' => round($persentase, 2),
                        'sisa_anggaran' => $target - $realisasi,
                        'jumlah_transaksi' => $jumlahTransaksi,
                    ],
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
        return Sts::query()
            ->where('Jn_Pajak', $jenisPajak)
            ->where('Status_Bayar', 1)
            ->whereYear('Tgl_Bayar', $tahun);
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
            'no_sspd' => $item->No_SSPD,
            'no_ketetapan' => $item->No_Ketetapan,
            'no_pokok_wp' => $item->No_Pokok_WP,
            'nama_pemilik' => $item->Nama_Pemilik,
            'alamat_pemilik' => $item->Alamat_Pemilik,
            'mata_anggaran' => $item->Mata_Anggaran,
            'nilai' => $nilai,
            'denda' => $denda,
            'total_bayar' => $nilai + $denda,
            'tgl_bayar' => $item->Tgl_Bayar,
            'nama_channel' => $item->Nama_Channel,
            'pembayaran_ke' => $item->Pembayaran_Ke,
            'kode_pembayaran' => $item->kode_pembayaran,
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
                ->whereYear('tgl_rekam_byr_sppt', $tahun)
                ->select(DB::raw('SUM(jml_sppt_yg_dibayar) AS total_realisasi'))
                ->first();
                
            return $pbbData && $pbbData->total_realisasi ? (float) $pbbData->total_realisasi : 0;
        } catch (\Exception $e) {
            return 0; // Biarkan 0 jika koneksi gagal atau tabel tidak ada
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
            'Retribusi' => 'Retribusi Lainnya'
        ];
    }

    /**
     * Jenis pajak yang detail-nya belum dilayani oleh endpoint STS.
     */
    private function getUnsupportedDetailJenis(): array
    {
        return ['PBB', 'Opsen PKB', 'Opsen BBNKB'];
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
     * Data target statis (TODO: Ganti menjadi data dari database jika tersedia)
     */
    private function getTargets(): array
    {
        return [
            'PBB' => 57950007794,
            'BPHTB' => 23000000000,
            'Pajak Restoran' => 4000000000,
            'Pajak Penerangan' => 76500000000,
            'Pajak Hotel' => 350000000,
            'Pajak Parkir' => 150000000,
            'Pajak Hiburan' => 80000000,
            'Pajak Reklame' => 1000000000,
            'Pajak Air Tanah' => 2000000000,
            'Pajak Galian' => 500000000,
            'Pajak Sarang Burung Walet' => 75000000,
            'Opsen PKB' => 10289383518,
            'Opsen BBNKB' => 11714467058,
            'Retribusi' => 6313000000
        ];
    }
}
