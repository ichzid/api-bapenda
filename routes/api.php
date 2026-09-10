<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PbbController;
use App\Http\Controllers\Api\RealisasiController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/realisasi-pajak', [RealisasiController::class, 'getRealisasi']);
Route::get('/realisasi-pajak/{jenisPajak}/detail', [RealisasiController::class, 'getRealisasiDetail']);
Route::get('/realisasi-retribusi', [RealisasiController::class, 'getRealisasiRetribusi']);
Route::get('/tren-penerimaan', [RealisasiController::class, 'getTrenPenerimaan']);

Route::get('/pbb/{nop}/history', [PbbController::class, 'history'])
    ->whereNumber('nop');
Route::get('/pbb/{nop}/{year}', [PbbController::class, 'detail'])
    ->whereNumber('nop')
    ->whereNumber('year');
