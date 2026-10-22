<?php

use App\Http\Controllers\Api\PbbController;
use App\Http\Controllers\Api\RealisasiController;
use App\Http\Controllers\Api\V1\Reports\Esptpd\MonitoringMakanMinumController;
use App\Http\Controllers\Api\V1\Reports\Esptpd\RekapInstansiMakanMinumController;
use App\Http\Controllers\Api\V1\Reports\PajakDaerah\ReportController;
use App\Http\Controllers\Api\V1\Reports\Retribusi\MonitoringController as RetribusiMonitoringController;
use App\Http\Controllers\Api\V1\Reports\Retribusi\RekapOpdController;
use App\Http\Middleware\ReportAuthentication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1/reports/esptpd')
    ->middleware(ReportAuthentication::class)
    ->controller(MonitoringMakanMinumController::class)
    ->group(function () {
        Route::get('/monitoring-makan-minum', 'show');
        Route::get('/monitoring-makan-minum/export', 'export');
    });

Route::prefix('v1/reports/esptpd')
    ->middleware(ReportAuthentication::class)
    ->controller(RekapInstansiMakanMinumController::class)
    ->group(function () {
        Route::get('/rekap-instansi-makan-minum', 'show');
        Route::get('/rekap-instansi-makan-minum/export', 'export');
    });

Route::prefix('v1/reports/retribusi')
    ->middleware(ReportAuthentication::class)
    ->controller(RetribusiMonitoringController::class)
    ->group(function () {
        Route::get('/sources', 'sources');
        Route::get('/monitoring', 'show');
        Route::get('/monitoring/export', 'export');
    });

Route::prefix('v1/reports/retribusi')
    ->middleware(ReportAuthentication::class)
    ->controller(RekapOpdController::class)
    ->group(function () {
        Route::get('/rekap-opd', 'show');
        Route::get('/rekap-opd/export', 'export');
    });

Route::prefix('v1/reports/pajak-daerah')
    ->middleware(ReportAuthentication::class)
    ->controller(ReportController::class)
    ->group(function () {
        Route::get('/sources', 'sources');

        Route::prefix('/{reportType}/{source}')
            ->whereIn('reportType', ['monitoring', 'ketetapan', 'rekap-ketetapan-per-wp', 'piutang', 'realisasi'])
            ->group(function () {
                Route::get('/', 'show');
                Route::get('/export', 'export');
            });
    });

Route::controller(RealisasiController::class)->group(function () {
    Route::get('/realisasi-pajak', 'getRealisasi');
    Route::get('/realisasi-pajak/{jenisPajak}/detail', 'getRealisasiDetail');
    Route::get('/realisasi-retribusi', 'getRealisasiRetribusi');
    Route::get('/tren-penerimaan', 'getTrenPenerimaan');
});

Route::prefix('pbb')
    ->controller(PbbController::class)
    ->group(function () {
        Route::get('/{nop}/history', 'history')
            ->whereNumber('nop');

        Route::get('/{nop}/{year}', 'detail')
            ->whereNumber('nop')
            ->whereNumber('year');
    });
