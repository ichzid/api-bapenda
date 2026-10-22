<?php

namespace App\Reports\PajakDaerah;

use App\Reports\PajakDaerah\Enums\CompatibilityMode;
use App\Reports\PajakDaerah\ValueObjects\ReportCriteria;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PaymentRepository
{
    public function byNumbers(array $numbers, array $source, CompatibilityMode $mode): array
    {
        $map = [];
        $trim = $source['id'] === 7 || $mode === CompatibilityMode::Corrected;
        foreach (array_chunk(array_values(array_unique(array_filter($numbers, fn ($number) => $number !== ''))), (int) config('reports.pajak_daerah.batch_size')) as $batch) {
            $query = DB::connection('sts')->table('STS_History')->whereIn('No_NOP', $batch);
            if ($mode === CompatibilityMode::Corrected) {
                $query->where('Jn_Pajak', $source['sts']);
                $this->applyDeterministicOrder($query);
            }
            foreach ($query->get() as $row) {
                $key = $trim ? trim((string) $row->No_NOP) : rtrim((string) $row->No_NOP);
                $map[$key] ??= $row;
            }
        }

        return $map;
    }

    public function paidByNumbers(array $numbers, array $source, ReportCriteria $criteria): array
    {
        [$field, $start, $end] = $criteria->range('Tgl_Bayar');
        $payments = [];

        foreach (array_chunk(array_values(array_unique(array_filter($numbers, fn ($number) => $number !== ''))), (int) config('reports.pajak_daerah.batch_size')) as $batch) {
            $query = DB::connection('sts')->table('STS_History')
                ->whereIn('No_NOP', $batch)
                ->where('Jn_Pajak', $source['sts'])
                ->where('Status_Bayar', 1)
                ->where($field, '>=', $start)
                ->where($field, '<', $end)
                ->orderBy('Tgl_Bayar');
            $this->applyDeterministicOrder($query);
            array_push($payments, ...$query->get()->all());
        }

        return $payments;
    }

    private function applyDeterministicOrder(Builder $query): void
    {
        $schema = DB::connection('sts')->getSchemaBuilder();
        foreach (config('reports.pajak_daerah.sts_order_columns', ['No_STS']) as $column) {
            if ($schema->hasColumn('STS_History', $column)) {
                $query->orderBy($column);
            }
        }
    }
}
