<?php

namespace App\Http\Controllers\Api\V1\Reports\Retribusi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\Retribusi\RekapOpdRequest;
use App\Reports\Retribusi\RekapOpdExportWriter;
use App\Reports\Retribusi\RekapOpdReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RekapOpdController extends Controller
{
    public function show(RekapOpdRequest $request, RekapOpdReport $report): JsonResponse
    {
        return response()->json($report->build($request->validated()));
    }

    public function export(RekapOpdRequest $request, RekapOpdReport $report, RekapOpdExportWriter $writer): BinaryFileResponse
    {
        $criteria = $request->validated();
        $dataset = $report->build($criteria, false);
        $path = $writer->write($dataset['data'], (int) $criteria['year']);
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], true, 'attachment');
        $response->setContentDisposition('attachment', 'rekap-ketetapan-retribusi-per-opd-'.$criteria['year'].'.xlsx');

        return $response->deleteFileAfterSend(true);
    }
}
