<?php

namespace App\Reports\PajakDaerah;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ReportExportWriter
{
    private const AMOUNT_COLUMNS = ['principal_amount', 'penalty_amount', 'total_amount', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'annual_total'];

    private const AMOUNT_NUMBER_FORMAT = '0';

    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month];
    }

    public function write(array $columns, array $rows, array $metadata): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr((string) ($metadata['title'] ?? 'Laporan'), 0, 31));
        $spreadsheet->getProperties()
            ->setTitle((string) ($metadata['title'] ?? 'Laporan Pajak Daerah'))
            ->setSubject((string) ($metadata['report_type'] ?? ''));

        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $printedAt = CarbonImmutable::now('Asia/Jakarta');
        $metadataRows = [
            (string) ($metadata['title'] ?? 'Laporan Pajak Daerah'),
            (string) ($metadata['period'] ?? ''),
            sprintf('Dicetak pada: %d %s %d %s WIB', $printedAt->day, self::monthName($printedAt->month), $printedAt->year, $printedAt->format('H:i')),
        ];
        foreach ($metadataRows as $row => $value) {
            $row++;
            $sheet->setCellValueExplicit("A{$row}", $value, DataType::TYPE_STRING);
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $headerRow = 5;
        foreach ($columns as $columnIndex => $column) {
            $sheet->setCellValueExplicit([$columnIndex + 1, $headerRow], $column['label'], DataType::TYPE_STRING);
        }
        $amountColumns = array_merge(self::AMOUNT_COLUMNS, $metadata['amount_columns'] ?? []);
        foreach ($rows as $rowIndex => $row) {
            foreach ($columns as $columnIndex => $column) {
                $value = data_get($row, $column['key']);
                $coordinate = [$columnIndex + 1, $rowIndex + $headerRow + 1];
                if (in_array($column['key'], $amountColumns, true)) {
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_NUMERIC);
                    $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(self::AMOUNT_NUMBER_FORMAT);
                } elseif (is_string($value) || $value === null) {
                    $sheet->setCellValueExplicit($coordinate, $value ?? '', DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
        }
        $lastRow = max($headerRow, $sheet->getHighestDataRow());
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A6');
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        foreach ($columns as $index => $column) {
            $width = in_array($column['key'], ['opd_name', 'nama_instansi', 'alamat_instansi'], true) ? 30 : 15;
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        $path = tempnam(sys_get_temp_dir(), (string) ($metadata['temporary_prefix'] ?? 'pajak-daerah-'));
        if ($path === false) {
            throw new \RuntimeException('Gagal membuat file ekspor sementara.');
        }
        try {
            (new Xlsx($spreadsheet))->save($path);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $path;
    }
}
