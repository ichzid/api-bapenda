<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Sts;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

                // Accumulate totals
                $totalTargetKeseluruhan += $target;
                $totalRealisasiKeseluruhan += $realisasi;

                return [
                    'jenis_pajak' => $namaBaru,
                    'target'      => $target,
                    'realisasi'   => $realisasi,
                    'persentase'  => round($persentase, 2),
                    'selisih'     => $selisih,
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
