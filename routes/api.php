<?php

use App\Http\Controllers\ApiController;
use App\Http\Middleware\VerifyApiKey;
use Illuminate\Support\Facades\Route;

Route::get('/', [ApiController::class, 'root']);

Route::middleware([VerifyApiKey::class])->group(function () {
    Route::get('/db-test', [ApiController::class, 'dbTest']);
    Route::get('/model-info', [ApiController::class, 'modelInfo']);
    Route::post('/sensor', [ApiController::class, 'receiveSensor']);
    Route::get('/status', [ApiController::class, 'getStatus']);
    Route::get('/history', [ApiController::class, 'history']);
    Route::post('/control', [ApiController::class, 'control']);
    Route::post('/predict', [ApiController::class, 'predict']);
    Route::get('/config', [ApiController::class, 'getConfig']);
    Route::post('/reset-rain', [ApiController::class, 'resetRain']);
});
