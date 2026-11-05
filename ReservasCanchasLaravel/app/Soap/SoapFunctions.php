<?php

use App\Services\CanchaService;
use App\Services\ReservaService;

if (!function_exists('respuestaSoap')) {
    function respuestaSoap(array $datos): string
    {
        return json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}


/*
|--------------------------------------------------------------------------
| OPERACIÓN 1: registrarCancha
|--------------------------------------------------------------------------
*/

if (!function_exists('registrarCancha')) {
    function registrarCancha(
        string $codigo,
        string $nombre,
        string $tipo,
        string $estado
    ): string {

        try {

            $service = app(CanchaService::class);

            $resultado = $service->registrarCancha([
                'codigo' => $codigo,
                'nombre' => $nombre,
                'tipo' => $tipo,
                'estado' => $estado
            ]);

            return respuestaSoap([
                'exito' => true,
                'datos' => $resultado
            ]);

        } catch (Throwable $e) {

            return respuestaSoap([
                'exito' => false,
                'mensaje' => $e->getMessage()
            ]);
        }
    }
}


/*
|--------------------------------------------------------------------------
| OPERACIÓN 2: consultarCancha
|--------------------------------------------------------------------------
*/

if (!function_exists('consultarCancha')) {
    function consultarCancha(int $id): string
    {
        try {

            $service = app(CanchaService::class);

            $cancha = $service->consultarCanchaPorId($id);

            return respuestaSoap([
                'exito' => true,
                'datos' => $cancha
            ]);

        } catch (Throwable $e) {

            return respuestaSoap([
                'exito' => false,
                'mensaje' => $e->getMessage()
            ]);
        }
    }
}


/*
|--------------------------------------------------------------------------
| OPERACIÓN 3: listarCanchas
|--------------------------------------------------------------------------
*/

if (!function_exists('listarCanchas')) {
    function listarCanchas(): string
    {
        try {

            $service = app(CanchaService::class);

            $resultado = $service->listarCanchas();

            return respuestaSoap([
                'exito' => true,
                'datos' => $resultado
            ]);

        } catch (Throwable $e) {

            return respuestaSoap([
                'exito' => false,
                'mensaje' => $e->getMessage()
            ]);
        }
    }
}


/*
|--------------------------------------------------------------------------
| OPERACIÓN 4: actualizarCancha
|--------------------------------------------------------------------------
*/

if (!function_exists('actualizarCancha')) {
    function actualizarCancha(
        int $id,
        string $nombre,
        string $tipo,
        string $estado
    ): string {

        try {

            $service = app(CanchaService::class);

            $resultado = $service->actualizarCanchaPorId(
                $id,
                [
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'estado' => $estado
                ]
            );

            return respuestaSoap([
                'exito' => true,
                'datos' => $resultado
            ]);

        } catch (Throwable $e) {

            return respuestaSoap([
                'exito' => false,
                'mensaje' => $e->getMessage()
            ]);
        }
    }
}


/*
|--------------------------------------------------------------------------
| OPERACIÓN 5: registrarReserva
|--------------------------------------------------------------------------
*/

if (!function_exists('registrarReserva')) {
    function registrarReserva(
        int $canchaId,
        int $usuarioId,
        string $fecha,
        string $hora,
        string $estado
    ): string {

        try {

            $service = app(ReservaService::class);

            $resultado = $service->registrarReserva([
                'cancha_id' => $canchaId,
                'usuario_id' => $usuarioId,
                'fecha' => $fecha,
                'hora' => $hora,
                'estado' => $estado
            ]);

            return respuestaSoap([
                'exito' => true,
                'datos' => $resultado
            ]);

        } catch (Throwable $e) {

            return respuestaSoap([
                'exito' => false,
                'mensaje' => $e->getMessage()
            ]);
        }
    }
}


/*
|--------------------------------------------------------------------------
| OPERACIÓN 6: consultarReservas
|--------------------------------------------------------------------------
*/

if (!function_exists('consultarReservas')) {
    function consultarReservas(): string
    {
        try {

            $service = app(ReservaService::class);

            $resultado = $service->listarReservas();

            return respuestaSoap([
                'exito' => true,
                'datos' => $resultado
            ]);

        } catch (Throwable $e) {

            return respuestaSoap([
                'exito' => false,
                'mensaje' => $e->getMessage()
            ]);
        }
    }
}