<?php

namespace App\Reports\Retribusi;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class RekapOpdReport
{
    public const MONTHS = [
        'january', 'february', 'march', 'april', 'may', 'june',
        'july', 'august', 'september', 'october', 'november', 'december',
    ];

    public const COLUMNS = ['number', 'opd_id', 'opd_name', ...self::MONTHS, 'annual_total'];

    public function build(array $criteria, bool $paginate = true): array
    {
        $year = (int) $criteria['year'];
        $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year));
        $end = $start->modify('+1 year');
        $transactions = DB::connection('eretribusi_db')->table('lap_skrd')
            ->join('jenis_retribusi', 'lap_skrd.idretribusi', '=', 'jenis_retribusi.id_jenis')
            ->join('opd_retribusi', 'jenis_retribusi.opd_id', '=', 'opd_retribusi.id_opd')
            ->where('jenis_retribusi.status', 1)
            ->where('lap_skrd.tgl_transaksi', '>=', $start->format('Y-m-d H:i:s'))
            ->where('lap_skrd.tgl_transaksi', '<', $end->format('Y-m-d H:i:s'))
            ->select([
                'opd_retribusi.id_opd as opd_id', 'opd_retribusi.nama_opd as opd_name',
                'lap_skrd.tgl_transaksi as transaction_date', 'lap_skrd.jlh_bayar as amount',
            ])->get();

        $rows = [];
        foreach ($transactions as $transaction) {
            $id = (string) $transaction->opd_id;
            if (! isset($rows[$id])) {
                $rows[$id] = array_fill_keys(self::MONTHS, '0') + [
                    'number' => 0,
                    'opd_id' => $id,
                    'opd_name' => (string) $transaction->opd_name,
                    'annual_total' => '0',
                ];
            } elseif (strnatcasecmp((string) $transaction->opd_name, $rows[$id]['opd_name']) < 0) {
                $rows[$id]['opd_name'] = (string) $transaction->opd_name;
            }
            $month = (int) substr((string) $transaction->transaction_date, 5, 2);
            if ($month < 1 || $month > 12) {
                continue;
            }
            $amount = $this->decimal($transaction->amount);
            $key = self::MONTHS[$month - 1];
            $rows[$id][$key] = $this->decimalAdd($rows[$id][$key], $amount);
            $rows[$id]['annual_total'] = $this->decimalAdd($rows[$id]['annual_total'], $amount);
        }

        $rows = array_values($rows);
        if (count($rows) > (int) config('reports.pajak_daerah.max_rows')) {
            throw new UnprocessableEntityHttpException('Jumlah data melebihi batas operasional laporan. Persempit filter.');
        }
        if (! empty($criteria['search'])) {
            $needle = mb_strtolower((string) $criteria['search']);
            $rows = array_values(array_filter($rows, fn (array $row): bool => str_contains(mb_strtolower($row['opd_id']), $needle)
                || str_contains(mb_strtolower($row['opd_name']), $needle)));
        }

        $sortBy = $criteria['sort_by'] ?? 'opd_name';
        $direction = ($criteria['sort_direction'] ?? 'asc') === 'desc' ? -1 : 1;
        usort($rows, function (array $left, array $right) use ($sortBy, $direction): int {
            $comparison = in_array($sortBy, [...self::MONTHS, 'annual_total'], true)
                ? $this->compareDecimal($left[$sortBy], $right[$sortBy])
                : strnatcasecmp($left[$sortBy], $right[$sortBy]);
            if ($comparison === 0) {
                $comparison = strnatcasecmp($left['opd_id'], $right['opd_id']);
            }

            return $comparison * $direction;
        });

        $totals = array_fill_keys([...self::MONTHS, 'annual_total'], '0');
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $column) {
                $totals[$column] = $this->decimalAdd($totals[$column], $row[$column]);
            }
        }
        $total = count($rows);
        $page = (int) ($criteria['page'] ?? 1);
        $perPage = (int) ($criteria['per_page'] ?? 15);
        $offset = $paginate ? ($page - 1) * $perPage : 0;
        if ($paginate) {
            $rows = array_slice($rows, $offset, $perPage);
        }
        foreach ($rows as $index => &$row) {
            $row['number'] = $offset + $index + 1;
        }

        return ['meta' => [
            'report_type' => 'rekap-opd', 'source' => 'eretribusi', 'year' => $year,
            'total' => $total, 'row_count' => count($rows),
            'pagination' => $paginate ? ['current_page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))] : null,
            'columns' => self::COLUMNS, 'totals' => $totals,
        ], 'data' => $rows];
    }

    private function decimal(mixed $value): string
    {
        $value = trim((string) ($value ?? '0'));

        return preg_match('/^-?\d+(?:\.\d+)?$/', $value) ? $value : '0';
    }

    private function decimalAdd(string $left, string $right): string
    {
        $scale = max(strlen(strstr($left, '.') ?: '') - 1, strlen(strstr($right, '.') ?: '') - 1);
        $toInteger = function (string $value) use ($scale): string {
            $negative = str_starts_with($value, '-');
            [$integer, $fraction] = array_pad(explode('.', ltrim($value, '+-'), 2), 2, '');
            $digits = ltrim($integer.str_pad($fraction, $scale, '0'), '0') ?: '0';

            return $negative && $digits !== '0' ? '-'.$digits : $digits;
        };
        $a = $toInteger($left);
        $b = $toInteger($right);
        $negativeA = str_starts_with($a, '-');
        $negativeB = str_starts_with($b, '-');
        $absoluteA = ltrim($a, '-');
        $absoluteB = ltrim($b, '-');
        if ($negativeA === $negativeB) {
            $carry = 0;
            $result = '';
            $length = max(strlen($absoluteA), strlen($absoluteB));
            $absoluteA = str_pad($absoluteA, $length, '0', STR_PAD_LEFT);
            $absoluteB = str_pad($absoluteB, $length, '0', STR_PAD_LEFT);
            for ($index = $length - 1; $index >= 0; $index--) {
                $sum = (int) $absoluteA[$index] + (int) $absoluteB[$index] + $carry;
                $result = ($sum % 10).$result;
                $carry = intdiv($sum, 10);
            }
            $result = ($carry ? $carry : '').$result;
            $negative = $negativeA;
        } else {
            $comparison = strlen($absoluteA) <=> strlen($absoluteB) ?: ($absoluteA <=> $absoluteB);
            [$larger, $smaller, $negative] = $comparison >= 0
                ? [$absoluteA, $absoluteB, $negativeA] : [$absoluteB, $absoluteA, $negativeB];
            $smaller = str_pad($smaller, strlen($larger), '0', STR_PAD_LEFT);
            $borrow = 0;
            $result = '';
            for ($index = strlen($larger) - 1; $index >= 0; $index--) {
                $difference = (int) $larger[$index] - (int) $smaller[$index] - $borrow;
                $borrow = $difference < 0 ? 1 : 0;
                $result = (($difference + 10) % 10).$result;
            }
        }
        $result = ltrim($result, '0') ?: '0';
        if ($scale > 0) {
            $result = str_pad($result, $scale + 1, '0', STR_PAD_LEFT);
            $result = rtrim(rtrim(substr($result, 0, -$scale).'.'.substr($result, -$scale), '0'), '.');
        }

        return ($negative && $result !== '0' ? '-' : '').$result;
    }

    private function compareDecimal(string $left, string $right): int
    {
        $difference = $this->decimalAdd($left, str_starts_with($right, '-') ? ltrim($right, '-') : '-'.$right);

        return $difference === '0' ? 0 : (str_starts_with($difference, '-') ? -1 : 1);
    }
}
