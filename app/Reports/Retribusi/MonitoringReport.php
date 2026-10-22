<?php

namespace App\Reports\Retribusi;

use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class MonitoringReport
{
    public const COLUMNS = ['number', 'source_record_id', 'transaction_date', 'taxpayer_number', 'document_number', 'opd_id', 'opd_name', 'tax_period', 'assessment_amount', 'payment_amount', 'payment_date', 'payment_status', 'status_code', 'payment_match_status', 'retribution_type_id', 'retribution_type_name'];

    public const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function sources(): array
    {
        $rows = DB::connection('eretribusi_db')->table('jenis_retribusi')
            ->where('status', 1)
            ->select(['id_jenis', 'nama_retribusi', 'opd_id'])
            ->orderBy('nama_retribusi')->orderBy('id_jenis')->get();

        return ['data' => $rows->map(fn ($row) => [
            'id' => (string) $row->id_jenis,
            'name' => (string) $row->nama_retribusi,
            'opd_id' => $row->opd_id === null ? null : (string) $row->opd_id,
        ])->all(), 'meta' => ['total' => $rows->count()]];
    }

    public function build(array $criteria, bool $paginate = true): array
    {
        $source = $this->resolveSource($criteria['source'] ?? null);
        $query = $this->query($criteria);
        $count = $query->count();
        if ($count > (int) config('reports.pajak_daerah.max_rows')) {
            throw new UnprocessableEntityHttpException('Jumlah data melebihi batas operasional laporan. Persempit filter.');
        }

        $records = $query->orderBy('lap_skrd.id_laporan')->get()->all();
        $mode = $criteria['mode'] ?? 'compatibility';
        $payments = $this->payments($records, $mode);
        $rows = [];
        foreach ($records as $index => $record) {
            $key = $this->key($record->document_number, $mode);
            $payment = $payments[$key] ?? null;
            $status = $payment ? (string) $payment->Status_Bayar : null;
            $paid = $status === '1';
            $unpaid = $status === '0';
            $rows[] = [
                'number' => 0,
                'source_record_id' => (string) $record->source_record_id,
                'transaction_date' => $this->date($record->transaction_date),
                'taxpayer_number' => (string) $record->taxpayer_number,
                'document_number' => (string) $record->document_number,
                'opd_id' => (string) $record->opd_id,
                'opd_name' => (string) $record->opd_name,
                'tax_period' => trim((string) $record->tax_month).' '.(string) $record->tax_year,
                'assessment_amount' => $this->money($record->assessment_amount),
                'payment_amount' => $paid ? $this->money($payment->Nilai) : '0',
                'payment_date' => $paid ? $this->date($payment->Tgl_Bayar) : null,
                'payment_status' => $paid ? 'Sudah Bayar' : ($unpaid ? 'Belum Bayar' : '-'),
                'status_code' => $paid ? 'paid' : ($unpaid ? 'unpaid' : 'unknown'),
                'payment_match_status' => $payment ? 'found' : 'not_found',
                'retribution_type_id' => (string) $record->retribution_type_id,
                'retribution_type_name' => (string) $record->retribution_type_name,
                '_order' => $index,
            ];
        }

        $rows = $this->filterAndSort($rows, $criteria, $paginate ? 'desc' : 'asc');
        $total = count($rows);
        $totals = ['assessment_amount' => '0', 'payment_amount' => '0'];
        foreach ($rows as $row) {
            $totals['assessment_amount'] = $this->decimalAdd($totals['assessment_amount'], $row['assessment_amount']);
            $totals['payment_amount'] = $this->decimalAdd($totals['payment_amount'], $row['payment_amount']);
        }
        $page = (int) ($criteria['page'] ?? 1);
        $perPage = (int) ($criteria['per_page'] ?? 15);
        $offset = $paginate ? ($page - 1) * $perPage : 0;
        if ($paginate) {
            $rows = array_slice($rows, $offset, $perPage);
        }
        foreach ($rows as $index => &$row) {
            unset($row['_order']);
            $row['number'] = $offset + $index + 1;
        }

        return ['meta' => [
            'report_type' => 'monitoring', 'source' => 'eretribusi', 'compatibility_mode' => $mode,
            'year' => (int) $criteria['year'], 'month' => $criteria['month'] ?? null,
            'retribution' => $source, 'total' => $total, 'row_count' => count($rows),
            'pagination' => $paginate ? ['current_page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))] : null,
            'columns' => self::COLUMNS, 'totals' => $totals,
        ], 'data' => $rows];
    }

    private function resolveSource(?string $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        $row = DB::connection('eretribusi_db')->table('jenis_retribusi')
            ->where('id_jenis', $id)
            ->where('status', 1)
            ->first(['id_jenis', 'nama_retribusi', 'opd_id']);
        if (! $row) {
            throw new UnprocessableEntityHttpException('Jenis retribusi tidak ditemukan atau tidak aktif.');
        }

        return ['id' => (string) $row->id_jenis, 'name' => (string) $row->nama_retribusi, 'opd_id' => $row->opd_id === null ? null : (string) $row->opd_id];
    }

    private function query(array $criteria): Builder
    {
        $year = (int) $criteria['year'];
        $month = empty($criteria['month']) ? 1 : (int) $criteria['month'];
        $start = (new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month)));
        $end = empty($criteria['month'])
            ? $start->modify('+1 year')
            : $start->modify('+1 month');

        $query = DB::connection('eretribusi_db')->table('lap_skrd')
            ->join('jenis_retribusi', 'lap_skrd.idretribusi', '=', 'jenis_retribusi.id_jenis')
            ->join('opd_retribusi', 'jenis_retribusi.opd_id', '=', 'opd_retribusi.id_opd')
            ->where('jenis_retribusi.status', 1)
            ->where('lap_skrd.tgl_transaksi', '>=', $start->format('Y-m-d H:i:s'))
            ->where('lap_skrd.tgl_transaksi', '<', $end->format('Y-m-d H:i:s'))
            ->select([
                'lap_skrd.id_laporan as source_record_id', 'lap_skrd.tgl_transaksi as transaction_date',
                'lap_skrd.nowp as taxpayer_number', 'lap_skrd.no_form as document_number',
                'opd_retribusi.id_opd as opd_id', 'opd_retribusi.nama_opd as opd_name',
                'lap_skrd.masa_pajak as tax_month', 'lap_skrd.thn_pajak as tax_year',
                'lap_skrd.jlh_bayar as assessment_amount', 'jenis_retribusi.id_jenis as retribution_type_id',
                'jenis_retribusi.nama_retribusi as retribution_type_name',
            ]);
        if (! empty($criteria['source'])) {
            $query->where('lap_skrd.idretribusi', $criteria['source']);
        }

        return $query;
    }

    private function payments(array $records, string $mode): array
    {
        $result = [];
        $numbers = array_values(array_unique(array_map(fn ($r) => $this->key($r->document_number, $mode), $records)));
        foreach (array_chunk($numbers, (int) config('reports.pajak_daerah.batch_size', 500)) as $chunk) {
            $query = DB::connection('sts')->table('STS_History')->whereIn('No_NOP', $chunk)
                ->select(['No_NOP', 'Status_Bayar', 'Nilai', 'Tgl_Bayar']);
            if ($mode === 'corrected') {
                $query->orderBy('Tgl_Bayar')->orderBy('No_NOP');
            }
            foreach ($query->get() as $row) {
                $result[$this->key($row->No_NOP, $mode)] ??= $row;
            }
        }

        return $result;
    }

    private function filterAndSort(array $rows, array $criteria, string $defaultDirection): array
    {
        if (! empty($criteria['search'])) {
            $needle = mb_strtolower($criteria['search']);
            $rows = array_values(array_filter($rows, function ($row) use ($needle) {
                foreach (self::COLUMNS as $field) {
                    if ($field !== 'number' && str_contains(mb_strtolower((string) ($row[$field] ?? '')), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }
        $field = $criteria['sort_by'] ?? 'source_record_id';
        $direction = (($criteria['sort_by'] ?? null) ? ($criteria['sort_direction'] ?? 'asc') : $defaultDirection) === 'desc' ? -1 : 1;
        usort($rows, function ($left, $right) use ($field, $direction) {
            $a = (string) ($left[$field] ?? '');
            $b = (string) ($right[$field] ?? '');
            $comparison = in_array($field, ['source_record_id', 'assessment_amount', 'payment_amount'], true)
                ? $this->decimalCompare($a, $b) : strnatcasecmp($a, $b);
            if ($comparison !== 0) {
                return $comparison * $direction;
            }
            $tie = $this->decimalCompare($left['source_record_id'], $right['source_record_id']);

            return $tie !== 0 ? $tie : ($left['_order'] <=> $right['_order']);
        });

        return $rows;
    }

    private function key(mixed $value, string $mode): string
    {
        return $mode === 'corrected' ? trim((string) $value) : rtrim((string) $value);
    }

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? '0'));

        return preg_match('/^-?\d+(?:\.\d+)?$/', $value) ? $value : '0';
    }

    private function date(mixed $value): ?string
    {
        $value = substr(trim((string) $value), 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function decimalCompare(string $a, string $b): int
    {
        $negativeA = str_starts_with($a, '-');
        $negativeB = str_starts_with($b, '-');
        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }
        $a = ltrim($a, '+-0') ?: '0';
        $b = ltrim($b, '+-0') ?: '0';
        [$ai, $af] = array_pad(explode('.', $a, 2), 2, '');
        [$bi, $bf] = array_pad(explode('.', $b, 2), 2, '');
        $cmp = strlen($ai) <=> strlen($bi) ?: ($ai <=> $bi);
        if (! $cmp) {
            $cmp = str_pad($af, max(strlen($af), strlen($bf)), '0') <=> str_pad($bf, max(strlen($af), strlen($bf)), '0');
        }

        return $negativeA ? -$cmp : $cmp;
    }

    private function decimalAdd(string $a, string $b): string
    {
        $scale = max(0, strlen(strstr($a, '.') ?: '') - 1, strlen(strstr($b, '.') ?: '') - 1);
        if (function_exists('bcadd')) {
            $sum = bcadd($a, $b, $scale);

            return $scale > 0 ? (rtrim(rtrim($sum, '0'), '.') ?: '0') : $sum;
        }
        $convert = fn ($v) => (int) round((float) $v * (10 ** $scale));
        $value = (string) ($convert($a) + $convert($b));
        if ($scale) {
            $value = substr(str_pad($value, $scale + 1, '0', STR_PAD_LEFT), 0, -$scale).'.'.substr(str_pad($value, $scale + 1, '0', STR_PAD_LEFT), -$scale);

            return rtrim(rtrim($value, '0'), '.') ?: '0';
        }

        return $value;
    }
}
