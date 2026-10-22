<?php

namespace App\Http\Controllers\Api\V1\Reports\PajakDaerah;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\PajakDaerah\ReportRequest;
use App\Reports\PajakDaerah\Enums\ReportType;
use App\Reports\PajakDaerah\ReportEngine;
use App\Reports\PajakDaerah\ReportExportWriter;
use App\Reports\PajakDaerah\SourceRegistry;
use App\Reports\PajakDaerah\ValueObjects\ReportCriteria;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function sources(SourceRegistry $registry): JsonResponse
    {
        return response()->json(['data' => $registry->all()]);
    }

    public function show(ReportRequest $request, string $reportType, string $source, SourceRegistry $registry, ReportEngine $engine): JsonResponse
    {
        $type = ReportType::tryFrom($reportType);
        if (! $type) {
            return response()->json(['message' => 'Jenis laporan tidak didukung.'], 404);
        }
        try {
            $definition = $registry->get($source);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($engine->build($type, $definition, ReportCriteria::fromArray($request->validated())));
    }

    public function export(ReportRequest $request, string $reportType, string $source, SourceRegistry $registry, ReportEngine $engine, ReportExportWriter $writer): JsonResponse|BinaryFileResponse
    {
        $type = ReportType::tryFrom($reportType);
        if (! $type) {
            return response()->json(['message' => 'Jenis laporan tidak didukung.'], 404);
        }
        try {
            $definition = $registry->get($source);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }
        $criteria = ReportCriteria::fromArray($request->validated());
        $dataset = $engine->buildAll($type, $definition, $criteria);
        $period = $type === ReportType::RekapKetetapanPerWp
            ? (string) $criteria->year
            : ($criteria->month
                ? sprintf('%s %d', ReportExportWriter::monthName($criteria->month), $criteria->year)
                : (string) $criteria->year);
        $path = $writer->write($engine->exportColumns($type, $definition), $dataset['data'], [
            'title' => $type === ReportType::RekapKetetapanPerWp
                ? 'Rekap Ketetapan per Wajib Pajak '.$definition['name']
                : 'Laporan '.$type->value.' '.$definition['name'],
            'period' => 'Periode: '.$period,
            'report_type' => $type->value,
        ]);
        $filename = implode('-', array_filter([
            $type->value,
            $source,
            (string) $criteria->year,
            $criteria->month ? sprintf('%02d', $criteria->month) : null,
        ])).'.xlsx';

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], true, 'attachment');
        $response->setContentDisposition('attachment', $filename);

        return $response->deleteFileAfterSend(true);
    }
}
