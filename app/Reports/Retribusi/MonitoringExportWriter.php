<?php

namespace App\Reports\Retribusi;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class MonitoringExportWriter
{
    public function write(array $rows, int $year, ?int $month, ?array $source): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Retribusi');
        $title = $source ? 'LAPORAN '.mb_strtoupper($source['name']) : 'LAPORAN RETRIBUSI GLOBAL';
        $period = $month ? 'PERIODE '.mb_strtoupper(MonitoringReport::MONTHS[$month]).' '.$year : 'TAHUN '.$year;
        $printed = CarbonImmutable::now('Asia/Jakarta');
        $metadata = [$title, $period, sprintf('Dicetak pada: %d %s %d %s WIB', $printed->day, MonitoringReport::MONTHS[$printed->month], $printed->year, $printed->format('H:i'))];
        foreach ($metadata as $index => $value) {
            $row = $index + 1;
            $sheet->setCellValueExplicit("A$row", $value, DataType::TYPE_STRING);
            $sheet->mergeCells("A$row:J$row");
            $sheet->getStyle("A$row:J$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getStyle('A1:J3')->getFont()->setBold(true);
        $headers = ['No', 'Tanggal Transaksi', 'No. NPWRD', 'Nomor SKRD', 'Nama OPD', 'Masa Retribusi', 'Nilai Ketetapan', 'Jumlah Bayar', 'Tanggal Bayar', 'Keterangan'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 5], $header, DataType::TYPE_STRING);
        }
        foreach ($rows as $index => $row) {
            $excelRow = $index + 6;
            $values = [$row['number'], $this->displayDate($row['transaction_date']), $row['taxpayer_number'], $row['document_number'], $row['opd_name'], $row['tax_period'], $row['assessment_amount'], $row['payment_amount'], $this->displayDate($row['payment_date']) ?? '-', $row['payment_status']];
            foreach ($values as $column => $value) {
                $coordinate = [$column + 1, $excelRow];
                if (in_array($column, [0, 6, 7], true)) {
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicit($coordinate, (string) ($value ?? '-'), DataType::TYPE_STRING);
                }
            }
            $sheet->getStyle("G$excelRow:H$excelRow")->getNumberFormat()->setFormatCode('0');
        }
        $lastRow = max(5, $sheet->getHighestDataRow());
        $sheet->getStyle("A5:J$lastRow")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('A5:J5')->getFont()->setBold(true);
        $sheet->getStyle('A5:J5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A6');
        $sheet->setAutoFilter("A5:J$lastRow");
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        foreach ([8, 16, 18, 22, 30, 20, 18, 18, 16, 16] as $index => $width) {
            $sheet->getColumnDimension(chr(65 + $index))->setWidth($width);
        }
        $path = tempnam(sys_get_temp_dir(), 'retribusi-');
        if ($path === false) {
            throw new \RuntimeException('Gagal membuat file ekspor sementara.');
        }
        try {
            (new Xlsx($spreadsheet))->save($path);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $path;
    }

    private function displayDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        return substr($date, 8, 2).'-'.substr($date, 5, 2).'-'.substr($date, 0, 4);
    }
}
