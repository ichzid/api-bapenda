<?php

namespace App\Http\Controllers\Api\V1\Reports\Esptpd;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\Esptpd\RekapInstansiMakanMinumRequest;
use App\Reports\Esptpd\RekapInstansiMakanMinumExportWriter;
use App\Reports\Esptpd\RekapInstansiMakanMinumReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RekapInstansiMakanMinumController extends Controller
{
    public function show(RekapInstansiMakanMinumRequest $request, RekapInstansiMakanMinumReport $report): JsonResponse
    {
        return response()->json($report->build($request->validated()));
    }

    public function export(RekapInstansiMakanMinumRequest $request, RekapInstansiMakanMinumReport $report, RekapInstansiMakanMinumExportWriter $writer): BinaryFileResponse
    {
        $criteria = $request->validated();
        $dataset = $report->build($criteria, false);
        $path = $writer->write($dataset['data'], (int) $criteria['year']);
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], true, 'attachment');
        $response->setContentDisposition('attachment', 'rekap-instansi-makan-minum-opd-'.$criteria['year'].'.xlsx');

        return $response->deleteFileAfterSend(true);
    }
}
