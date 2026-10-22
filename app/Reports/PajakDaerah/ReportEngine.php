<?php

namespace App\Reports\PajakDaerah;

use App\Reports\PajakDaerah\Enums\CompatibilityMode;
use App\Reports\PajakDaerah\Enums\ReportType;
use App\Reports\PajakDaerah\ValueObjects\ReportCriteria;
use App\Support\Reports\PajakDaerah\PenaltyCalculator;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ReportEngine
{
    public function __construct(private PaymentRepository $payments, private PenaltyCalculator $penalties) {}

    public function build(ReportType $type, array $source, ReportCriteria $criteria): array
    {
        return $this->dataset($type, $source, $criteria, true, 'desc');
    }

    public function buildAll(ReportType $type, array $source, ReportCriteria $criteria): array
    {
        return $this->dataset($type, $source, $criteria, false, 'asc');
    }

    public function exportColumns(ReportType $type, array $source): array
    {
        $labels = [
            'number' => 'No.', 'document_number' => 'Nomor Dokumen',
            'taxpayer_number' => 'NPWPD', 'taxpayer_name' => 'Nama Wajib Pajak', 'address' => 'Alamat',
            'tax_period' => 'Masa Pajak', 'assessment_date' => 'Tanggal Ketetapan', 'due_date' => 'Jatuh Tempo',
            'penalty_months' => 'Bulan Denda', 'principal_amount' => 'Pokok', 'penalty_amount' => 'Denda',
            'total_amount' => 'Total', 'payment_status' => 'Status Pembayaran', 'payment_date' => 'Tanggal Bayar',
            'payment_code' => 'Kode Pembayaran',
            'january' => 'Jan', 'february' => 'Feb', 'march' => 'Mar', 'april' => 'Apr',
            'may' => 'Mei', 'june' => 'Jun', 'july' => 'Jul', 'august' => 'Agu',
            'september' => 'Sep', 'october' => 'Okt', 'november' => 'Nov', 'december' => 'Des',
            'annual_total' => 'Total',
        ];
        if ($type === ReportType::RekapKetetapanPerWp) {
            $labels['number'] = 'No';
            $labels['taxpayer_name'] = 'Nama WP/Usaha';
        }
        $details = [
            1 => ['monthly_revenue' => 'Pendapatan Bulanan', 'rate' => 'Tarif'],
            2 => ['monthly_revenue' => 'Pendapatan Bulanan', 'rate' => 'Tarif'],
            3 => ['entertainment_type' => 'Jenis Hiburan', 'monthly_revenue' => 'Pendapatan Bulanan', 'rate' => 'Tarif'],
            4 => ['title' => 'Judul', 'location' => 'Lokasi', 'length' => 'Panjang', 'width' => 'Lebar', 'sides' => 'Sisi', 'count' => 'Jumlah', 'months' => 'Bulan', 'strategic' => 'Strategis', 'rate' => 'Tarif', 'period_start' => 'Awal Masa', 'period_end' => 'Akhir Masa'],
            5 => ['power' => 'Daya', 'power_rate' => 'Tarif Daya', 'consumption' => 'Konsumsi', 'rate' => 'Tarif'],
            6 => ['location' => 'Lokasi', 'mineral_type' => 'Jenis Mineral', 'sale_value' => 'Nilai Jual', 'extraction' => 'Pengambilan', 'rate' => 'Tarif'],
            7 => ['location' => 'Lokasi', 'water_source' => 'Sumber Air', 'classification' => 'Golongan', 'volume' => 'Volume', 'rate' => 'Tarif'],
            8 => ['monthly_revenue' => 'Pendapatan Bulanan', 'rate' => 'Tarif'],
            9 => ['monthly_revenue' => 'Pendapatan Bulanan', 'rate' => 'Tarif'],
        ];
        $columns = [];
        foreach ($this->columns($type) as $key) {
            if ($key === 'details') {
                foreach ($details[$source['id']] as $detailKey => $label) {
                    $columns[] = ['key' => 'details.'.$detailKey, 'label' => $label];
                }
            } else {
                $columns[] = ['key' => $key, 'label' => $labels[$key]];
            }
        }

        return $columns;
    }

    private function dataset(ReportType $type, array $source, ReportCriteria $criteria, bool $paginate, string $defaultDirection): array
    {
        if ($type === ReportType::RekapKetetapanPerWp) {
            return $this->recapByTaxpayer($source, $criteria, $paginate);
        }
        if ($type === ReportType::Piutang && $source['key'] === 'pbjt-tenaga-listrik' && $criteria->mode === CompatibilityMode::Compatibility) {
            throw new UnprocessableEntityHttpException('Piutang listrik tidak didukung oleh laporan legacy.');
        }
        $sourceQuery = $this->sourceQuery($type, $source, $criteria);
        $this->guardCount($sourceQuery->count());
        $rows = $type === ReportType::Realisasi ? $this->realization($source, $criteria, $sourceQuery) : $this->sourceBased($type, $source, $criteria);
        $rows = $this->filterSort($rows, $criteria, $this->columns($type), $type, $defaultDirection);
        $total = count($rows);
        $offset = $paginate ? ($criteria->page - 1) * $criteria->perPage : 0;
        if ($paginate) {
            $rows = array_slice($rows, $offset, $criteria->perPage);
        }
        foreach ($rows as $i => &$row) {
            unset($row['_order']);
            $row['number'] = $offset + $i + 1;
        }

        return ['meta' => ['report_type' => $type->value, 'source' => $source['key'], 'compatibility_mode' => $criteria->mode->value, 'total' => $total, 'row_count' => count($rows), 'pagination' => $paginate ? ['current_page' => $criteria->page, 'per_page' => $criteria->perPage, 'last_page' => max(1, (int) ceil($total / $criteria->perPage))] : null, 'columns' => $this->columns($type)], 'data' => $rows];
    }

    private function recapByTaxpayer(array $source, ReportCriteria $criteria, bool $paginate): array
    {
        $columns = $this->columns(ReportType::RekapKetetapanPerWp);
        $records = $this->sourceQuery(ReportType::RekapKetetapanPerWp, $source, $criteria)->get()->all();
        $months = [
            1 => 'january', 2 => 'february', 3 => 'march', 4 => 'april',
            5 => 'may', 6 => 'june', 7 => 'july', 8 => 'august',
            9 => 'september', 10 => 'october', 11 => 'november', 12 => 'december',
        ];
        $rows = [];
        $countedRecords = [];
        foreach ($records as $record) {
            $taxpayerNumber = trim((string) ($record->{$source['npwpd']} ?? ''));
            $name = trim((string) ($record->registered_name ?? $record->{$source['name_field']} ?? ''));
            $address = trim((string) ($record->registered_address ?? $record->{$source['address']} ?? ''));
            if (! isset($rows[$taxpayerNumber])) {
                $rows[$taxpayerNumber] = array_fill_keys(array_values($months), '0') + [
                    'number' => 0,
                    'taxpayer_number' => $taxpayerNumber,
                    'taxpayer_name' => $name,
                    'address' => $address,
                    'annual_total' => '0',
                    '_order' => count($rows),
                    '_identity' => $name."\0".$address,
                ];
            } elseif ($name."\0".$address > $rows[$taxpayerNumber]['_identity']) {
                $rows[$taxpayerNumber]['taxpayer_name'] = $name;
                $rows[$taxpayerNumber]['address'] = $address;
                $rows[$taxpayerNumber]['_identity'] = $name."\0".$address;
            }

            $recordKey = (string) ($record->{$source['pk']} ?? '');
            if (isset($countedRecords[$recordKey])) {
                continue;
            }
            $countedRecords[$recordKey] = true;
            $month = $months[(int) substr((string) ($record->tgl_cetak ?? ''), 5, 2)] ?? null;
            if ($month !== null) {
                $amount = $this->money($record->{$source['amount']} ?? 0);
                $rows[$taxpayerNumber][$month] = $this->decimalAdd($rows[$taxpayerNumber][$month], $amount);
                $rows[$taxpayerNumber]['annual_total'] = $this->decimalAdd($rows[$taxpayerNumber]['annual_total'], $amount);
            }
        }
        $rows = array_values($rows);
        $this->guardCount(count($rows));
        foreach ($rows as &$row) {
            unset($row['_identity']);
        }
        $rows = $this->filterSort($rows, $criteria, $columns, ReportType::RekapKetetapanPerWp, 'asc');
        $total = count($rows);
        $offset = $paginate ? ($criteria->page - 1) * $criteria->perPage : 0;
        if ($paginate) {
            $rows = array_slice($rows, $offset, $criteria->perPage);
        }
        foreach ($rows as $index => &$row) {
            unset($row['_order']);
            $row['number'] = $offset + $index + 1;
        }

        return ['meta' => ['report_type' => ReportType::RekapKetetapanPerWp->value, 'source' => $source['key'], 'compatibility_mode' => $criteria->mode->value, 'total' => $total, 'row_count' => count($rows), 'pagination' => $paginate ? ['current_page' => $criteria->page, 'per_page' => $criteria->perPage, 'last_page' => max(1, (int) ceil($total / $criteria->perPage))] : null, 'columns' => $columns], 'data' => $rows];
    }

    private function sourceBased(ReportType $type, array $source, ReportCriteria $criteria): array
    {
        $local = $this->sourceQuery($type, $source, $criteria)->get()->all();
        $payments = [];
        if ($type !== ReportType::Ketetapan) {
            $payments = $this->payments->byNumbers(array_map(fn ($r) => $this->key($r->{$source['number']} ?? '', $source, $criteria), $local), $source, $criteria->mode);
        }
        $rows = [];
        foreach ($local as $index => $r) {
            $sts = $payments[$this->key($r->{$source['number']} ?? '', $source, $criteria)] ?? null;
            if ($type === ReportType::Piutang && $sts && (int) $sts->Status_Bayar === 1) {
                continue;
            }
            $row = $this->base($r, $source, $sts) + ['source_match_status' => 'found', '_order' => $index];
            if ($type === ReportType::Monitoring) {
                $amounts = $this->penalties->calculate($r->{$source['amount']} ?? 0, $sts, $criteria->asOfDate);
                $amounts['principal_amount'] = (string) $amounts['tax_amount'];
                unset($amounts['tax_amount']);
                $amounts['status_code'] = $amounts['payment_status_code'];
                unset($amounts['payment_status_code']);
                $row = array_merge($row, $amounts, ['payment_status' => $amounts['status_code'] === 'paid' ? 'Sudah Bayar' : 'Belum Bayar', 'payment_match_status' => $sts ? 'found' : 'not_found']);
            } elseif ($type !== ReportType::Ketetapan) {
                $row['payment_status'] = 'Belum Bayar';
                $row['status_code'] = 'unpaid';
                $row['payment_match_status'] = $sts ? 'found' : 'not_found';
            }
            $row['details'] = $this->details($r, $source, $criteria);
            $rows[] = $row;
        }

        return $rows;
    }

    private function sourceQuery(ReportType $type, array $source, ReportCriteria $criteria): Builder
    {
        $query = DB::connection('simpada')->table($source['table']);
        if (isset($source['join']) && in_array($type->value, $source['join_reports'] ?? [], true)) {
            $join = $source['join'];
            if ($join['type'] === 'advertisement') {
                $query->leftJoin($join['table'], function ($clause) use ($join) {
                    $clause->on($join['left'], '=', $join['right'])->whereRaw('(x_tmpreklame.p * x_tmpreklame.l) BETWEEN x_sewareklame.min AND x_sewareklame.max');
                })->select('x_tmpreklame.*', 'x_sewareklame.harga as harga_sewa')->groupBy('x_tmpreklame.tmpreklame_id');
            } elseif ($join['type'] === 'left') {
                $query->leftJoin($join['table'], $join['left'], '=', $join['right']);
            } else {
                $query->join($join['table'], $join['left'], '=', $join['right']);
                if (isset($join['identity'])) {
                    $query->select([
                        $source['table'].'.*',
                        $join['table'].'.'.$join['identity']['name'].' as registered_name',
                        $join['table'].'.'.$join['identity']['address'].' as registered_address',
                    ]);
                }
            }
        }
        if ($type !== ReportType::Realisasi) {
            [$field, $start, $end] = $criteria->range($source['table'].'.tgl_cetak');
            $query->where($field, '>=', $start)->where($field, '<', $end);
        }

        return $query->orderBy($source['table'].'.'.$source['pk']);
    }

    private function realization(array $source, ReportCriteria $criteria, Builder $sourceQuery): array
    {
        $local = $sourceQuery->get()->all();
        $localsByNumber = [];
        foreach ($local as $row) {
            $localsByNumber[$this->key($row->{$source['number']} ?? '', $source, $criteria)] ??= $row;
        }
        $payments = $this->payments->paidByNumbers(array_keys($localsByNumber), $source, $criteria);

        $rows = [];
        foreach ($payments as $paymentIndex => $payment) {
            $key = $this->key($payment->No_NOP ?? '', $source, $criteria);
            if (isset($localsByNumber[$key])) {
                $row = $localsByNumber[$key];
                $rows[] = [
                    'number' => 0,
                    'source_record_id' => (string) $row->{$source['pk']},
                    'document_number' => (string) ($row->{$source['number']} ?? ''),
                    'taxpayer_number' => (string) ($row->{$source['npwpd']} ?? ''),
                    'taxpayer_name' => trim((string) ($row->registered_name ?? $row->{$source['name_field']} ?? '')),
                    'address' => trim((string) ($row->registered_address ?? $row->{$source['address']} ?? '')),
                    'tax_period' => trim(($row->masa_pajak ?? '').' '.($row->thn_pajak ?? '')),
                    'assessment_date' => $this->date($payment->Tgl_STS ?? null),
                    'payment_date' => $this->date($payment->Tgl_Bayar ?? null),
                    'due_date' => $this->date($payment->jatuh_tempo ?? null),
                    'principal_amount' => $this->money($payment->Nilai ?? 0),
                    'penalty_amount' => '0',
                    'total_amount' => $this->money($payment->Nilai ?? 0),
                    'payment_status' => 'Sudah Bayar',
                    'status_code' => 'paid',
                    'payment_match_status' => 'found',
                    'source_match_status' => 'found',
                    'payment_code' => (string) ($payment->Kode_Pengesahan ?? ''),
                    'details' => [],
                    '_order' => $paymentIndex,
                ];
            }
        }
        $this->guardCount(count($rows));

        return $rows;
    }

    private function base(object $r, array $s, ?object $sts): array
    {
        return ['number' => 0, 'source_record_id' => (string) ($r->{$s['pk']} ?? ''), 'document_number' => (string) ($r->{$s['number']} ?? ''), 'taxpayer_number' => (string) ($r->{$s['npwpd']} ?? ''), 'taxpayer_name' => (string) ($r->registered_name ?? $r->{$s['name_field']} ?? ''), 'address' => (string) ($r->registered_address ?? $r->{$s['address']} ?? ''), 'tax_period' => trim(($r->masa_pajak ?? '').' '.($r->thn_pajak ?? '')), 'assessment_date' => $this->date($r->tgl_cetak ?? null), 'payment_date' => $this->date($sts->Tgl_Bayar ?? null), 'due_date' => $this->date($sts->jatuh_tempo ?? null), 'principal_amount' => $this->money($r->{$s['amount']} ?? 0)];
    }

    private function details(object $r, array $s, ReportCriteria $c): array
    {
        $year = $c->year;

        return match ($s['id']) {
            1,8 => ['monthly_revenue' => $this->money($r->pendapatan ?? 0), 'rate' => '10 %'], 2 => ['monthly_revenue' => $this->money($r->pend_makan_minum ?? 0), 'rate' => (string) ($r->persentase ?? '').'%'], 3 => ['entertainment_type' => $r->nama ?? null, 'monthly_revenue' => $this->money($r->pendapatan ?? 0), 'rate' => $year <= 2023 ? '10 %' : $this->percent($r->persentase ?? 0)], 4 => ['title' => $r->judul ?? null, 'location' => $r->alamat_reklame ?? null, 'length' => $r->p ?? null, 'width' => $r->l ?? null, 'sides' => $r->s ?? null, 'count' => $r->jumlah ?? null, 'months' => $r->jlh_bulan ?? null, 'strategic' => $this->strategic($r), 'rate' => '25 %', 'period_start' => $this->date($r->tglmasa_pajak_ax ?? null), 'period_end' => $this->date($r->tglmasa_pajak_bx ?? null)], 5 => ['power' => $r->jlh_daya ?? null, 'power_rate' => $r->tarif ?? null, 'consumption' => $r->jlh_listrik ?? null, 'rate' => '1.5 %'], 6 => ['location' => $r->lokasi ?? null, 'mineral_type' => $r->jenis_galian ?? null, 'sale_value' => $r->nilai_jual ?? null, 'extraction' => $r->jml_pengambilan ?? null, 'rate' => $year <= 2023 ? '25 %' : '20 %'], 7 => ['location' => $r->lokasi ?? null, 'water_source' => ($r->sumber_air ?? null) == 1 ? 'Sumur Bor' : ($c->mode === CompatibilityMode::Corrected && ($r->sumber_air ?? null) == 2 ? 'Mata Air' : null), 'classification' => $r->gol ?? null, 'volume' => $r->volume ?? null, 'rate' => $year <= 2023 ? '10 %' : '20 %'], 9 => ['monthly_revenue' => $this->money($r->pendapatan ?? 0), 'rate' => ($r->persentase ?? null) === null ? '-' : $r->persentase.' %']
        };
    }

    private function strategic(object $r): string
    {
        if (($r->id_lokasi ?? 1) == 1 || empty($r->harga_sewa)) {
            return 'Tidak';
        } $size = (float) ($r->p ?? 0) * (float) ($r->l ?? 0);
        $base = ((float) $r->harga_sewa * .25 / 12) * (float) ($r->s ?? 0) * (float) ($r->jumlah ?? 0) * (float) ($r->jlh_bulan ?? 0);
        $amount = (float) ($r->nilai_pajak ?? 0);

        return abs($amount - $base * 2) < 2 || ($size > 6 && abs($amount - $base * 2 * $size) < 2) ? 'Ya' : 'Tidak';
    }

    private function key(mixed $v, array $s, ReportCriteria $c): string
    {
        return $s['id'] === 7 || $c->mode === CompatibilityMode::Corrected ? trim((string) $v) : rtrim((string) $v);
    }

    private function date(mixed $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '' || str_starts_with($v, '0000-00-00') || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return null;
        } $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($v, 0, 10));

        return $date && $date->format('Y-m-d') === substr($v, 0, 10) ? substr($v, 0, 10) : null;
    }

    private function money(mixed $v): string
    {
        $v = trim((string) ($v ?? '0'));

        return preg_match('/^-?\d+(?:\.\d+)?$/', $v) ? $v : '0';
    }

    private function percent(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $this->money($v) * 100, 10, '.', ''), '0'), '.').'%';
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

    private function guardCount(int $count): void
    {
        if ($count > (int) config('reports.pajak_daerah.max_rows')) {
            throw new UnprocessableEntityHttpException('Jumlah data melebihi batas operasional laporan. Persempit filter.');
        }
    }

    private function filterSort(array $rows, ReportCriteria $c, array $visible, ReportType $type, string $defaultDirection): array
    {
        if ($c->search) {
            $n = mb_strtolower($c->search);
            $rows = array_values(array_filter($rows, function ($r) use ($n, $visible) {
                foreach ($visible as $field) {
                    $value = $field === 'details' ? implode(' ', array_map(fn ($v) => (string) $v, $r['details'] ?? [])) : ($r[$field] ?? null);
                    if (str_contains(mb_strtolower((string) $value), $n)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $sortBy = $c->sortBy ?? match ($type) {
            ReportType::Realisasi => 'payment_date',
            ReportType::RekapKetetapanPerWp => 'taxpayer_name',
            default => 'source_record_id',
        };
        $direction = ($c->sortBy ? $c->sortDirection : $defaultDirection) === 'desc' ? -1 : 1;
        usort($rows, function ($a, $b) use ($sortBy, $direction, $type) {
            $left = $a[$sortBy] ?? $a['details'][$sortBy] ?? null;
            $right = $b[$sortBy] ?? $b['details'][$sortBy] ?? null;
            $cmp = is_numeric($left) && is_numeric($right) ? $this->decimalCompare((string) $left, (string) $right) : strnatcasecmp((string) $left, (string) $right);
            if ($cmp !== 0) {
                return $cmp * $direction;
            }
            if ($type === ReportType::RekapKetetapanPerWp && $sortBy !== 'taxpayer_number') {
                $cmp = strcmp((string) $a['taxpayer_number'], (string) $b['taxpayer_number']);
            }

            return $cmp === 0 ? ($a['_order'] <=> $b['_order']) : $cmp;
        });

        return $rows;
    }

    private function decimalCompare(string $a, string $b): int
    {
        $negativeA = str_starts_with($a, '-');
        $negativeB = str_starts_with($b, '-');
        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }$a = ltrim($a, '+-0') ?: '0';
        $b = ltrim($b, '+-0') ?: '0';
        [$ai,$af] = array_pad(explode('.', $a, 2), 2, '');
        [$bi,$bf] = array_pad(explode('.', $b, 2), 2, '');
        $cmp = strlen($ai) <=> strlen($bi);
        if (! $cmp) {
            $cmp = $ai <=> $bi;
        }if (! $cmp) {
            $cmp = str_pad($af, max(strlen($af), strlen($bf)), '0') <=> str_pad($bf, max(strlen($af), strlen($bf)), '0');
        }

        return $negativeA ? -$cmp : $cmp;
    }

    private function columns(ReportType $t): array
    {
        return match ($t) {
            ReportType::Monitoring => ['number', 'document_number', 'taxpayer_number', 'taxpayer_name', 'address', 'tax_period', 'assessment_date', 'due_date', 'penalty_months', 'principal_amount', 'penalty_amount', 'total_amount', 'payment_status', 'payment_date', 'details'],ReportType::Ketetapan => ['number', 'document_number', 'taxpayer_number', 'taxpayer_name', 'address', 'tax_period', 'details', 'principal_amount', 'assessment_date'],ReportType::RekapKetetapanPerWp => ['number', 'taxpayer_number', 'taxpayer_name', 'address', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'annual_total'],ReportType::Piutang => ['number', 'document_number', 'taxpayer_number', 'taxpayer_name', 'address', 'tax_period', 'assessment_date', 'principal_amount', 'payment_status'],ReportType::Realisasi => ['number', 'payment_date', 'document_number', 'taxpayer_number', 'taxpayer_name', 'address', 'tax_period', 'assessment_date', 'due_date', 'principal_amount', 'payment_code']
        };
    }
}
