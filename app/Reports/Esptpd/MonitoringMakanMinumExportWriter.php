<?php

namespace App\Reports\Esptpd;

use App\Reports\PajakDaerah\ReportExportWriter;

final class MonitoringMakanMinumExportWriter
{
    private ReportExportWriter $writer;

    public function __construct(ReportExportWriter $writer)
    {
        $this->writer = $writer;
    }

    public function write(array $rows, int $year, ?int $month): string
    {
        $labels = [
            'No', 'Tanggal Input', 'NPWPD', 'No. SPTPD', 'Nama Instansi',
            'Alamat Instansi', 'Nama Usaha', 'Alamat Usaha', 'Masa Pajak',
            'Nilai Pajak', 'Status Bayar', 'Tanggal Bayar', 'Nomor STS',
        ];
        $columns = array_map(
            fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
            MonitoringMakanMinumReport::COLUMNS,
            $labels,
        );
        $period = $month
            ? sprintf('PERIODE %s %d', mb_strtoupper(ReportExportWriter::monthName($month)), $year)
            : 'PERIODE TAHUN '.$year;

        return $this->writer->write($columns, $rows, [
            'title' => 'LAPORAN MONITORING PAJAK MAKANAN DAN MINUMAN OPD',
            'period' => $period,
            'report_type' => 'monitoring-makan-minum',
            'amount_columns' => ['nilai_pajak'],
            'temporary_prefix' => 'esptpd-',
        ]);
    }
}
