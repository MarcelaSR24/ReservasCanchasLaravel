<?php

use App\Http\Middleware\CanConsultBilling;
use App\Http\Middleware\CanListEquipment;
use App\Http\Middleware\CanManageBilling;
use App\Http\Middleware\CanManageEquipment;
use App\Http\Middleware\CanUpdatePaymentStatus;
use App\Http\Middleware\IsAdministrator;
use App\Http\Middleware\JwtAuthenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->preventRequestForgery(
            except: [
                'soap',
            ]
        );

        $middleware->alias([
            'jwt.auth' => JwtAuthenticate::class,
            'is.administrator' => IsAdministrator::class,
            'can.manage.billing' => CanManageBilling::class,
            'can.update.payment.status' => CanUpdatePaymentStatus::class,
            'can.manage.equipment' => CanManageEquipment::class,
            'can.consult.billing' => CanConsultBilling::class,
            'can.list.equipment' => CanListEquipment::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
