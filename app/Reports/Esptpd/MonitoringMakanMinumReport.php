<?php

namespace App\Reports\Esptpd;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class MonitoringMakanMinumReport
{
    public const COLUMNS = [
        'no', 'tanggal_input', 'no_npwpd', 'no_sptpd', 'nama_instansi',
        'alamat_instansi', 'nama_usaha', 'alamat_usaha', 'masa_pajak',
        'nilai_pajak', 'status_bayar', 'tanggal_bayar', 'nomor_sts',
    ];

    public function build(array $criteria, bool $paginate = true): array
    {
        $query = $this->transactionQuery($criteria, $paginate);
        $count = (clone $query)->count();
        if ($count > (int) config('reports.pajak_daerah.max_rows')) {
            throw new UnprocessableEntityHttpException('Jumlah data melebihi batas operasional laporan. Persempit filter.');
        }

        $transactions = $query->get()->all();
        $mode = $criteria['mode'] ?? 'compatibility';
        $payments = $this->payments($transactions, $mode);
        $rows = [];
        foreach ($transactions as $index => $transaction) {
            $rawNumber = $this->normalize($transaction->no_sptpd ?? '', $mode);
            $payment = $payments[$rawNumber] ?? null;
            $paid = $payment !== null && (int) ($payment->Status_Bayar ?? 0) === 1;
            $rows[] = [
                'no' => 0,
                'tanggal_input' => $this->date($transaction->tgl_trans ?? null),
                'no_npwpd' => (string) ($transaction->no_npwpd ?? ''),
                'no_sptpd' => $rawNumber,
                'nama_instansi' => (string) ($transaction->nama_instansi ?? ''),
                'alamat_instansi' => (string) ($transaction->alamat_instansi ?? ''),
                'nama_usaha' => (string) ($transaction->nama_usaha ?? ''),
                'alamat_usaha' => (string) ($transaction->alamat_usaha ?? ''),
                'masa_pajak' => trim((string) ($transaction->masa_pajak ?? '').' '.(string) ($transaction->thn_pajak ?? '')),
                'nilai_pajak' => $this->decimal($transaction->pajak_terhutangx ?? 0),
                'status_bayar' => $paid ? 'Sudah Bayar' : 'Belum Bayar',
                'tanggal_bayar' => $paid ? $this->dateTime($payment->Tgl_Bayar ?? null) : '-',
                'nomor_sts' => $paid ? (string) ($payment->No_STS ?? '') : '-',
                '_id' => (string) ($transaction->transresto_id ?? ''),
                '_order' => $index,
            ];
        }

        $rows = $this->filterAndSort($rows, $criteria, $paginate);
        $total = count($rows);
        $page = (int) ($criteria['page'] ?? 1);
        $perPage = (int) ($criteria['per_page'] ?? 15);
        $offset = $paginate ? ($page - 1) * $perPage : 0;
        if ($paginate) {
            $rows = array_slice($rows, $offset, $perPage);
        }
        foreach ($rows as $index => &$row) {
            unset($row['_id'], $row['_order']);
            $row['no'] = $offset + $index + 1;
        }

        return [
            'meta' => [
                'report_type' => 'monitoring-makan-minum',
                'source' => 'simpada',
                'compatibility_mode' => $mode,
                'year' => (int) $criteria['year'],
                'month' => $criteria['month'] ?? null,
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

    private function transactionQuery(array $criteria, bool $paginate): Builder
    {
        $start = CarbonImmutable::create((int) $criteria['year'], isset($criteria['month']) ? (int) $criteria['month'] : 1, 1)->startOfDay();
        $end = isset($criteria['month']) ? $start->addMonth() : $start->addYear();

        return DB::connection('simpada')->table('sptpd_resto')
            ->where('tgl_trans', '>=', $start->format('Y-m-d H:i:s'))
            ->where('tgl_trans', '<', $end->format('Y-m-d H:i:s'))
            ->orderBy('transresto_id', $paginate ? 'desc' : 'asc');
    }

    private function payments(array $transactions, string $mode): array
    {
        $numbers = [];
        foreach ($transactions as $transaction) {
            $number = $this->normalize($transaction->no_sptpd ?? '', $mode);
            if ($number !== '') {
                $numbers[$number] = true;
            }
        }

        $map = [];
        $batchSize = max(1, (int) config('reports.pajak_daerah.batch_size', 500));
        foreach (array_chunk(array_keys($numbers), $batchSize) as $batch) {
            $query = DB::connection('sts')->table('STS_History')->whereIn('No_NOP', $batch);
            if ($mode === 'corrected') {
                $query->where('Jn_Pajak', 'Pajak Restoran')->orderBy('No_STS')->orderBy('Tgl_Bayar');
            }
            foreach ($query->get() as $payment) {
                $key = $this->normalize($payment->No_NOP ?? '', $mode);
                $map[$key] ??= $payment;
            }
        }

        return $map;
    }

    private function filterAndSort(array $rows, array $criteria, bool $paginate): array
    {
        if (! empty($criteria['search'])) {
            $needle = mb_strtolower($criteria['search']);
            $rows = array_values(array_filter($rows, function (array $row) use ($needle): bool {
                foreach (self::COLUMNS as $column) {
                    if (str_contains(mb_strtolower((string) ($row[$column] ?? '')), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $sortBy = $criteria['sort_by'] ?? '_id';
        $direction = isset($criteria['sort_by'])
            ? (($criteria['sort_direction'] ?? 'asc') === 'desc' ? -1 : 1)
            : ($paginate ? -1 : 1);
        usort($rows, function (array $left, array $right) use ($sortBy, $direction): int {
            $comparison = in_array($sortBy, ['nilai_pajak', '_id'], true)
                ? $this->compareDecimal($left[$sortBy], $right[$sortBy])
                : (($left[$sortBy] ?? '') <=> ($right[$sortBy] ?? ''));
            if ($comparison === 0) {
                return $this->compareDecimal($left['_id'], $right['_id']);
            }

            return $comparison * $direction;
        });

        return $rows;
    }

    private function normalize(mixed $value, string $mode): string
    {
        return $mode === 'corrected' ? trim((string) $value) : rtrim((string) $value);
    }

    private function decimal(mixed $value): string
    {
        $value = trim((string) ($value ?? '0'));

        return preg_match('/^-?\d+(?:\.\d+)?$/', $value) ? $value : '0';
    }

    private function compareDecimal(string $left, string $right): int
    {
        $normalize = function (string $value): array {
            $negative = str_starts_with($value, '-');
            [$integer, $fraction] = array_pad(explode('.', ltrim($value, '+-'), 2), 2, '');

            return [$negative, ltrim($integer, '0') ?: '0', rtrim($fraction, '0')];
        };
        [$negativeLeft, $integerLeft, $fractionLeft] = $normalize($left);
        [$negativeRight, $integerRight, $fractionRight] = $normalize($right);
        if ($negativeLeft !== $negativeRight) {
            return $negativeLeft ? -1 : 1;
        }
        $comparison = strlen($integerLeft) <=> strlen($integerRight) ?: ($integerLeft <=> $integerRight);
        if ($comparison === 0) {
            $length = max(strlen($fractionLeft), strlen($fractionRight));
            $comparison = str_pad($fractionLeft, $length, '0') <=> str_pad($fractionRight, $length, '0');
        }

        return $negativeLeft ? -$comparison : $comparison;
    }

    private function date(mixed $value): string
    {
        try {
            return CarbonImmutable::parse((string) $value)->format('d-m-Y');
        } catch (\Throwable $exception) {
            return '';
        }
    }

    private function dateTime(mixed $value): string
    {
        try {
            return CarbonImmutable::parse((string) $value)->format('d-m-Y H:i:s');
        } catch (\Throwable $exception) {
            return '-';
        }
    }
}
