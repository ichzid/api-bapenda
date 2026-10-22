<?php

namespace App\Reports\Retribusi;

use App\Reports\PajakDaerah\ReportExportWriter;

final class RekapOpdExportWriter
{
    private ReportExportWriter $writer;

    public function __construct(ReportExportWriter $writer)
    {
        $this->writer = $writer;
    }

    public function write(array $rows, int $year): string
    {
        $labels = [
            'No', 'ID OPD', 'Nama OPD', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Total',
        ];
        $columns = array_map(
            fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
            RekapOpdReport::COLUMNS,
            $labels,
        );

        return $this->writer->write($columns, $rows, [
            'title' => 'REKAP KETETAPAN RETRIBUSI PER OPD',
            'period' => 'TAHUN '.$year,
            'report_type' => 'rekap-opd',
            'amount_columns' => [...RekapOpdReport::MONTHS, 'annual_total'],
            'temporary_prefix' => 'retribusi-',
        ]);
    }
}
