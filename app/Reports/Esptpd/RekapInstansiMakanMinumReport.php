<?php

namespace App\Reports\Esptpd;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class RekapInstansiMakanMinumReport
{
    public const MONTHS = [
        'januari', 'februari', 'maret', 'april', 'mei', 'juni',
        'juli', 'agustus', 'september', 'oktober', 'november', 'desember',
    ];

    public const COLUMNS = [
        'no', 'no_npwpd', 'nama_instansi', 'alamat_instansi',
        ...self::MONTHS, 'total',
    ];

    public const SORTABLE_COLUMNS = [
        'no_npwpd', 'nama_instansi', 'alamat_instansi',
        ...self::MONTHS, 'total',
    ];

    public function build(array $criteria, bool $paginate = true): array
    {
        $year = (int) $criteria['year'];
        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $end = $start->addYear();
        $query = DB::connection('simpada')->table('sptpd_resto')
            ->where('tgl_trans', '>=', $start->format('Y-m-d H:i:s'))
            ->where('tgl_trans', '<', $end->format('Y-m-d H:i:s'));

        if ((clone $query)->count() > (int) config('reports.pajak_daerah.max_rows')) {
            throw new UnprocessableEntityHttpException('Jumlah data melebihi batas operasional laporan. Persempit filter.');
        }

        $rows = [];
        foreach ($query->get() as $transaction) {
            $number = rtrim((string) ($transaction->no_npwpd ?? ''));
            if (trim($number) === '') {
                continue;
            }
            if (! isset($rows[$number])) {
                $rows[$number] = array_fill_keys(self::MONTHS, '0') + [
                    'no' => 0,
                    'no_npwpd' => $number,
                    'nama_instansi' => '',
                    'alamat_instansi' => '',
                    'total' => '0',
                    '_latest_id' => null,
                ];
            }
            if ($rows[$number]['_latest_id'] === null || $this->compareDecimal((string) $transaction->transresto_id, (string) $rows[$number]['_latest_id']) > 0) {
                $rows[$number]['nama_instansi'] = (string) ($transaction->nama_instansi ?? '');
                $rows[$number]['alamat_instansi'] = (string) ($transaction->alamat_instansi ?? '');
                $rows[$number]['_latest_id'] = (string) $transaction->transresto_id;
            }

            $month = self::MONTHS[(int) CarbonImmutable::parse((string) $transaction->tgl_trans)->format('n') - 1];
            $amount = $this->decimal($transaction->pajak_terhutangx ?? 0);
            $rows[$number][$month] = $this->decimalAdd($rows[$number][$month], $amount);
            $rows[$number]['total'] = $this->decimalAdd($rows[$number]['total'], $amount);
        }

        $rows = array_values($rows);
        if (! empty($criteria['search'])) {
            $needle = mb_strtolower((string) $criteria['search']);
            $rows = array_values(array_filter($rows, fn (array $row): bool => str_contains(mb_strtolower($row['no_npwpd']), $needle)
                || str_contains(mb_strtolower($row['nama_instansi']), $needle)
                || str_contains(mb_strtolower($row['alamat_instansi']), $needle)));
        }

        $sortBy = $criteria['sort_by'] ?? 'nama_instansi';
        $direction = ($criteria['sort_direction'] ?? 'asc') === 'desc' ? -1 : 1;
        usort($rows, function (array $left, array $right) use ($sortBy, $direction): int {
            $comparison = in_array($sortBy, [...self::MONTHS, 'total'], true)
                ? $this->compareDecimal($left[$sortBy], $right[$sortBy])
                : strnatcasecmp($left[$sortBy], $right[$sortBy]);
            if ($comparison === 0) {
                $comparison = strnatcasecmp($left['no_npwpd'], $right['no_npwpd']);
            }

            return $comparison * $direction;
        });

        $total = count($rows);
        $page = (int) ($criteria['page'] ?? 1);
        $perPage = (int) ($criteria['per_page'] ?? 15);
        $offset = $paginate ? ($page - 1) * $perPage : 0;
        if ($paginate) {
            $rows = array_slice($rows, $offset, $perPage);
        }
        foreach ($rows as $index => &$row) {
            unset($row['_latest_id']);
            $row['no'] = $offset + $index + 1;
            $row = array_replace(array_fill_keys(self::COLUMNS, null), $row);
        }

        return [
            'meta' => [
                'report_type' => 'rekap-instansi-makan-minum',
                'source' => 'simpada',
                'year' => $year,
                'total' => $total,
                'row_count' => count($rows),
                'pagination' => $paginate ? [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ] : null,
                'columns' => self::COLUMNS,
            ],
            'data' => $rows,
        ];
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
                ? [$absoluteA, $absoluteB, $negativeA]
                : [$absoluteB, $absoluteA, $negativeB];
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
            $result = substr($result, 0, -$scale).'.'.substr($result, -$scale);
            $result = rtrim(rtrim($result, '0'), '.');
        }

        return ($negative && $result !== '0' ? '-' : '').$result;
    }

    private function compareDecimal(string $left, string $right): int
    {
        $difference = $this->decimalAdd($left, str_starts_with($right, '-') ? ltrim($right, '-') : '-'.$right);
        if ($difference === '0') {
            return 0;
        }

        return str_starts_with($difference, '-') ? -1 : 1;
    }
}
