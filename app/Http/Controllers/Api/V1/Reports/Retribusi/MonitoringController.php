<?php

namespace App\Http\Controllers\Api\V1\Reports\Retribusi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\Retribusi\MonitoringRequest;
use App\Reports\Retribusi\MonitoringExportWriter;
use App\Reports\Retribusi\MonitoringReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MonitoringController extends Controller
{
    public function sources(MonitoringReport $report): JsonResponse
    {
        return response()->json($report->sources());
    }

    public function show(MonitoringRequest $request, MonitoringReport $report): JsonResponse
    {
        return response()->json($report->build($request->validated()));
    }

    public function export(MonitoringRequest $request, MonitoringReport $report, MonitoringExportWriter $writer): BinaryFileResponse
    {
        $criteria = $request->validated();
        $dataset = $report->build($criteria, false);
        $month = isset($criteria['month']) ? (int) $criteria['month'] : null;
        $source = $dataset['meta']['retribution'];
        $path = $writer->write($dataset['data'], (int) $criteria['year'], $month, $source);
        $slug = $source ? Str::slug($source['name']) : 'retribusi-global';
        $filename = 'laporan-'.$slug.'-'.$criteria['year'].($month ? '-'.sprintf('%02d', $month) : '').'.xlsx';
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true, 'attachment');
        $response->setContentDisposition('attachment', $filename);

        return $response->deleteFileAfterSend(true);
    }
}
