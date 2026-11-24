<?php

use App\Services\CanchaService;
use App\Services\JwtService;
use App\Services\ReservaService;
use App\Services\SoapReservationAuthorizationService;

if (! function_exists('respuestaSoap')) {
    /** @param array<string, mixed> $datos */
    function respuestaSoap(array $datos): string
    {
        return json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}

if (! function_exists('validarTokenSoap')) {
    /** @return array<string, mixed>|false */
    function validarTokenSoap(string $token): array|false
    {
        try {
            $claims = app(JwtService::class)->validate($token);

            if (array_key_exists('token_use', $claims)
                || array_key_exists('scope', $claims)
                || array_key_exists('aud', $claims)) {
                return false;
            }

            return $claims;
        } catch (Throwable) {
            return false;
        }
    }
}

if (! function_exists('ValidateTokenService')) {
    function ValidateTokenService(string $token): string
    {
        $claims = validarTokenSoap($token);
        if ($claims === false) {
            return respuestaSoap(['exito' => false, 'mensaje' => 'Token JWT inválido, vencido o revocado.']);
        }

        return respuestaSoap([
            'exito' => true,
            'mensaje' => 'Token JWT válido. La firma HS256 fue verificada.',
            'usuario_id' => (int) $claims['sub'],
            'expira' => (int) $claims['exp'],
        ]);
    }
}

if (! function_exists('registrarCancha')) {
    function registrarCancha(string $token, string $codigo, string $nombre, string $tipo, string $estado): string
    {
        if (validarTokenSoap($token) === false) {
            return respuestaSoap(['exito' => false, 'mensaje' => 'JWT inválido, vencido o revocado. Operación no autorizada.']);
        }

        try {
            $resultado = app(CanchaService::class)->registrarCancha([
                'codigo' => $codigo,
                'nombre' => $nombre,
                'tipo' => $tipo,
                'estado' => $estado,
            ]);

            return respuestaSoap(['exito' => true, 'datos' => $resultado]);
        } catch (Throwable $exception) {
            return respuestaSoap(['exito' => false, 'mensaje' => $exception->getMessage()]);
        }
    }
}

if (! function_exists('consultarCancha')) {
    function consultarCancha(string $token, int $id): string
    {
        if (validarTokenSoap($token) === false) {
            return respuestaSoap(['exito' => false, 'mensaje' => 'JWT inválido, vencido o revocado. Operación no autorizada.']);
        }

        try {
            $cancha = app(CanchaService::class)->consultarCanchaPorId($id);

            return respuestaSoap(['exito' => true, 'datos' => $cancha]);
        } catch (Throwable $exception) {
            return respuestaSoap(['exito' => false, 'mensaje' => $exception->getMessage()]);
        }
    }
}

if (! function_exists('listarCanchas')) {
    function listarCanchas(string $token): string
    {
        if (validarTokenSoap($token) === false) {
            return respuestaSoap(['exito' => false, 'mensaje' => 'JWT inválido, vencido o revocado. Operación no autorizada.']);
        }

        try {
            return respuestaSoap(['exito' => true, 'datos' => app(CanchaService::class)->listarCanchas()]);
        } catch (Throwable $exception) {
            return respuestaSoap(['exito' => false, 'mensaje' => $exception->getMessage()]);
        }
    }
}

if (! function_exists('actualizarCancha')) {
    function actualizarCancha(string $token, int $id, string $nombre, string $tipo, string $estado): string
    {
        if (validarTokenSoap($token) === false) {
            return respuestaSoap(['exito' => false, 'mensaje' => 'JWT inválido, vencido o revocado. Operación no autorizada.']);
        }

        try {
            $resultado = app(CanchaService::class)->actualizarCanchaPorId($id, [
                'nombre' => $nombre,
                'tipo' => $tipo,
                'estado' => $estado,
            ]);

            return respuestaSoap(['exito' => true, 'datos' => $resultado]);
        } catch (Throwable $exception) {
            return respuestaSoap(['exito' => false, 'mensaje' => $exception->getMessage()]);
        }
    }
}

if (! function_exists('registrarReserva')) {
    function registrarReserva(string $token, int $canchaId, int $usuarioId, string $fecha, string $hora, string $estado): string
    {
        if (validarTokenSoap($token) === false) {
            return respuestaSoap(['exito' => false, 'mensaje' => 'JWT inválido, vencido o revocado. Operación no autorizada.']);
        }

        try {
            $resultado = app(ReservaService::class)->registrarReserva([
                'cancha_id' => $canchaId,
                'usuario_id' => $usuarioId,
                'fecha' => $fecha,
                'hora' => $hora,
                'estado' => $estado,
            ]);

            return respuestaSoap(['exito' => true, 'datos' => $resultado]);
        } catch (Throwable $exception) {
            return respuestaSoap(['exito' => false, 'mensaje' => $exception->getMessage()]);
        }
    }
}

if (! function_exists('consultarReservas')) {
    function consultarReservas(string $token): string
    {
        if (! app(SoapReservationAuthorizationService::class)->mayConsult($token)) {
            return respuestaSoap(['exito' => false, 'mensaje' => 'Token JWT no autorizado para consultar reservas.']);
        }

        try {
            return respuestaSoap(['exito' => true, 'datos' => app(ReservaService::class)->listarReservas()]);
        } catch (Throwable $exception) {
            return respuestaSoap(['exito' => false, 'mensaje' => $exception->getMessage()]);
        }
    }
}
