<?php

namespace App\Reports\Esptpd;

use App\Reports\PajakDaerah\ReportExportWriter;

final class RekapInstansiMakanMinumExportWriter
{
    private ReportExportWriter $writer;

    public function __construct(ReportExportWriter $writer)
    {
        $this->writer = $writer;
    }

    public function write(array $rows, int $year): string
    {
        $labels = [
            'No', 'NPWPD', 'Nama Instansi', 'Alamat Instansi',
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Total',
        ];
        $columns = array_map(
            fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
            RekapInstansiMakanMinumReport::COLUMNS,
            $labels,
        );

        return $this->writer->write($columns, $rows, [
            'title' => 'REKAP INSTANSI PAJAK MAKANAN DAN MINUMAN OPD',
            'period' => 'TAHUN '.$year,
            'report_type' => 'rekap-instansi-makan-minum',
            'amount_columns' => [...RekapInstansiMakanMinumReport::MONTHS, 'total'],
            'temporary_prefix' => 'esptpd-',
        ]);
    }
}
