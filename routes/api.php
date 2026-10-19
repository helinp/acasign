<?php

use App\Http\Controllers\Api\V1\DocumentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['acanet.jwt', 'throttle:30,1'])->group(function () {
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::post('/documents/{document}/cancel', [DocumentController::class, 'cancel']);
    Route::post('/documents/{document}/revoke', [DocumentController::class, 'revoke']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
});
