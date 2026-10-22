<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PbbController extends Controller
{
    public function history(string $nop): JsonResponse
    {
        $parts = $this->parseNop($nop);
        if ($parts === null) {
            return response()->json(['message' => 'NOP harus terdiri dari tepat 18 digit.'], 422);
        }

        $rows = $this->spptQuery($parts)
            ->leftJoin('pembayaran_sppt as p', function ($join) {
                $join->on('s.kd_propinsi', '=', 'p.kd_propinsi')
                    ->on('s.kd_dati2', '=', 'p.kd_dati2')
                    ->on('s.kd_kecamatan', '=', 'p.kd_kecamatan')
                    ->on('s.kd_kelurahan', '=', 'p.kd_kelurahan')
                    ->on('s.kd_blok', '=', 'p.kd_blok')
                    ->on('s.no_urut', '=', 'p.no_urut')
                    ->on('s.kd_jns_op', '=', 'p.kd_jns_op')
                    ->on('s.thn_pajak_sppt', '=', 'p.thn_pajak_sppt');
            })
            ->select([
                's.thn_pajak_sppt',
                's.nm_wp_sppt',
                's.tgl_jatuh_tempo_sppt',
                's.pbb_yg_harus_dibayar_sppt',
                DB::raw('COALESCE(SUM(p.jml_sppt_yg_dibayar), 0) as jumlah_dibayar'),
                DB::raw('COALESCE(SUM(p.denda_sppt), 0) as denda_dibayar'),
                DB::raw('MAX(p.tgl_rekam_byr_sppt) as tanggal_dibayar'),
            ])
            ->groupBy(
                's.thn_pajak_sppt',
                's.nm_wp_sppt',
                's.tgl_jatuh_tempo_sppt',
                's.pbb_yg_harus_dibayar_sppt'
            )
            ->orderByDesc('s.thn_pajak_sppt')
            ->get()
            ->map(fn ($row) => $this->formatRecord($row));

        if ($rows->isEmpty()) {
            return response()->json(['message' => 'Data PBB untuk NOP tersebut tidak ditemukan.'], 404);
        }

        return response()->json($rows);
    }

    public function detail(string $nop, int $year): JsonResponse
    {
        $parts = $this->parseNop($nop);
        if ($parts === null) {
            return response()->json(['message' => 'NOP harus terdiri dari tepat 18 digit.'], 422);
        }

        if ($year < 1992 || $year > (int) date('Y')) {
            return response()->json(['message' => 'Tahun pajak tidak valid.'], 422);
        }

        $sppt = $this->spptQuery($parts)
            ->where('s.thn_pajak_sppt', $year)
            ->first();

        if ($sppt === null) {
            return response()->json(['message' => 'Data PBB untuk NOP dan tahun tersebut tidak ditemukan.'], 404);
        }

        $payment = DB::connection('epbb_db')
            ->table('pembayaran_sppt')
            ->where($parts)
            ->where('thn_pajak_sppt', $year)
            ->selectRaw('COALESCE(SUM(jml_sppt_yg_dibayar), 0) as jumlah_dibayar')
            ->selectRaw('COALESCE(SUM(denda_sppt), 0) as denda_dibayar')
            ->selectRaw('MAX(tgl_rekam_byr_sppt) as tanggal_dibayar')
            ->first();

        $object = DB::connection('epbb_db')
            ->table('dat_objek_pajak')
            ->where($parts)
            ->first();

        $record = $this->formatRecord((object) [
            'thn_pajak_sppt' => $sppt->thn_pajak_sppt,
            'nm_wp_sppt' => $sppt->nm_wp_sppt,
            'tgl_jatuh_tempo_sppt' => $sppt->tgl_jatuh_tempo_sppt,
            'pbb_yg_harus_dibayar_sppt' => $sppt->pbb_yg_harus_dibayar_sppt,
            'jumlah_dibayar' => $payment->jumlah_dibayar,
            'denda_dibayar' => $payment->denda_dibayar,
            'tanggal_dibayar' => $payment->tanggal_dibayar,
        ]);

        $earthArea = (float) $sppt->luas_bumi_sppt;
        $buildingArea = (float) $sppt->luas_bng_sppt;
        $earthNjop = (float) $sppt->njop_bumi_sppt;
        $buildingNjop = (float) $sppt->njop_bng_sppt;
        $totalNjop = (float) $sppt->njop_sppt;
        $njoptkp = (float) $sppt->njoptkp_sppt;
        $taxableNjop = max(0, $totalNjop - $njoptkp);

        return response()->json(array_merge($record, [
            'nop' => $nop,
            'objectAddress' => $this->joinAddress([
                $object->jalan_op ?? null,
                $object->blok_kav_no_op ?? null,
                isset($object->rt_op) ? 'RT '.$object->rt_op : null,
                isset($object->rw_op) ? 'RW '.$object->rw_op : null,
            ]),
            'taxpayerAddress' => $this->joinAddress([
                $sppt->jln_wp_sppt,
                $sppt->blok_kav_no_wp_sppt,
                $sppt->rt_wp_sppt ? 'RT '.$sppt->rt_wp_sppt : null,
                $sppt->rw_wp_sppt ? 'RW '.$sppt->rw_wp_sppt : null,
                $sppt->kelurahan_wp_sppt,
                $sppt->kota_wp_sppt,
            ]),
            'npwp' => trim((string) $sppt->npwp_sppt) ?: '-',
            'earthArea' => $earthArea,
            'buildingArea' => $buildingArea,
            'earthRate' => $earthArea > 0 ? $earthNjop / $earthArea : 0,
            'buildingRate' => $buildingArea > 0 ? $buildingNjop / $buildingArea : 0,
            'earthNjop' => $earthNjop,
            'buildingNjop' => $buildingNjop,
            'totalNjop' => $totalNjop,
            'njoptkp' => $njoptkp,
            'taxableNjop' => $taxableNjop,
            'taxRate' => $taxableNjop > 0
                ? (float) $sppt->pbb_yg_harus_dibayar_sppt / $taxableNjop
                : 0,
        ]));
    }

    private function spptQuery(array $parts)
    {
        return DB::connection('epbb_db')
            ->table('sppt as s')
            ->where(array_combine(
                array_map(fn ($key) => 's.'.$key, array_keys($parts)),
                array_values($parts)
            ));
    }

    private function parseNop(string $nop): ?array
    {
        if (! preg_match('/^\d{18}$/', $nop)) {
            return null;
        }

        return [
            'kd_propinsi' => substr($nop, 0, 2),
            'kd_dati2' => substr($nop, 2, 2),
            'kd_kecamatan' => substr($nop, 4, 3),
            'kd_kelurahan' => substr($nop, 7, 3),
            'kd_blok' => substr($nop, 10, 3),
            'no_urut' => substr($nop, 13, 4),
            'kd_jns_op' => substr($nop, 17, 1),
        ];
    }

    private function formatRecord(object $row): array
    {
        $principal = (float) $row->pbb_yg_harus_dibayar_sppt;
        $paid = (float) $row->jumlah_dibayar;
        $paidPenalty = (float) $row->denda_dibayar;
        $isPaid = $paid >= $principal && $principal > 0;
        $penalty = $this->calculatePenalty(
            (string) $row->tgl_jatuh_tempo_sppt,
            $principal,
            $paid,
            $paidPenalty
        );

        return [
            'year' => (int) $row->thn_pajak_sppt,
            'taxpayerName' => trim((string) $row->nm_wp_sppt),
            'dueDate' => Carbon::parse($row->tgl_jatuh_tempo_sppt)->format('d-m-Y'),
            'penaltyInfo' => $penalty['months'].' bulan · '.$penalty['percent'].'%',
            'principal' => $principal,
            'penalty' => $penalty['amount'],
            'total' => $isPaid ? $principal + $paidPenalty : $penalty['total'],
            'paid' => $paid,
            'paidDate' => $row->tanggal_dibayar
                ? Carbon::parse($row->tanggal_dibayar)->format('d-m-Y')
                : '-',
            'status' => $isPaid ? 'Lunas' : 'Belum',
        ];
    }

    private function calculatePenalty(string $dueDate, float $principal, float $paid, float $paidPenalty): array
    {
        $remaining = max(0, $principal - $paid);
        if ($remaining <= 0) {
            return ['months' => 0, 'percent' => 0, 'amount' => $paidPenalty, 'total' => $principal + $paidPenalty];
        }

        $due = Carbon::parse($dueDate)->startOfDay();
        $today = Carbon::today('Asia/Jakarta');
        if ($today->lte($due)) {
            return ['months' => 0, 'percent' => 0, 'amount' => 0, 'total' => $remaining];
        }

        $cursor = $due->copy()->startOfMonth()->addMonth();
        $currentMonth = $today->copy()->startOfMonth();
        $months = 0;
        $rate = 0.0;

        while ($cursor->lte($currentMonth) && $months < 24) {
            $rate += $cursor->year <= 2023 ? 0.02 : 0.01;
            $months++;
            $cursor->addMonth();
        }

        $amount = round($rate * $remaining);

        return [
            'months' => $months,
            'percent' => round($rate * 100, 2),
            'amount' => $amount,
            'total' => $remaining + $amount,
        ];
    }

    private function joinAddress(array $parts): string
    {
        $address = collect($parts)
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->implode(', ');

        return $address !== '' ? $address : '-';
    }
}
