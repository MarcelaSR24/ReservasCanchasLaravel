<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CobroController;
use App\Http\Controllers\ImplementoController;
use App\Http\Controllers\SoapAuthController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('jwt.auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/soap/validate-token', [SoapAuthController::class, 'validateToken'])->middleware('throttle:20,1');
    Route::get('/me', [SoapAuthController::class, 'protectedOperation']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/cobros', [CobroController::class, 'store'])
        ->middleware('can.manage.billing')
        ->name('api.cobros.generar');
    Route::get('/cobros/{cobro}', [CobroController::class, 'show'])
        ->middleware('can.consult.billing')
        ->name('api.cobros.consultar');
    Route::patch('/cobros/{cobro}/estado', [CobroController::class, 'updateStatus'])
        ->middleware('can.update.payment.status')
        ->name('api.cobros.actualizarEstado');

    Route::get('/implementos', [ImplementoController::class, 'index'])
        ->middleware('can.list.equipment')
        ->name('api.implementos.listar');
    Route::post('/implementos', [ImplementoController::class, 'store'])
        ->middleware('can.manage.equipment')
        ->name('api.implementos.registrar');
    Route::post('/implementos/{implemento}/ajustar-stock', [ImplementoController::class, 'ajustarStock'])
        ->middleware('can.manage.equipment')
        ->name('api.implementos.ajustarStock');
});
