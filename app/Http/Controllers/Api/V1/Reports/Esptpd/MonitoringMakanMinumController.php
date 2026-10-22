<?php

namespace App\Http\Controllers\Api\V1\Reports\Esptpd;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\Esptpd\MonitoringMakanMinumRequest;
use App\Reports\Esptpd\MonitoringMakanMinumExportWriter;
use App\Reports\Esptpd\MonitoringMakanMinumReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MonitoringMakanMinumController extends Controller
{
    public function show(MonitoringMakanMinumRequest $request, MonitoringMakanMinumReport $report): JsonResponse
    {
        return response()->json($report->build($request->validated()));
    }

    public function export(MonitoringMakanMinumRequest $request, MonitoringMakanMinumReport $report, MonitoringMakanMinumExportWriter $writer): BinaryFileResponse
    {
        $criteria = $request->validated();
        $dataset = $report->build($criteria, false);
        $month = isset($criteria['month']) ? (int) $criteria['month'] : null;
        $path = $writer->write($dataset['data'], (int) $criteria['year'], $month);
        $filename = 'monitoring-makan-minum-opd-'.$criteria['year'].($month ? '-'.sprintf('%02d', $month) : '').'.xlsx';
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], true, 'attachment');
        $response->setContentDisposition('attachment', $filename);

        return $response->deleteFileAfterSend(true);
    }
}
