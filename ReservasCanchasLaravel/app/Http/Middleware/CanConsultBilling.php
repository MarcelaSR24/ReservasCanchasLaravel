<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CanConsultBilling
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->user()) {
            return response()->json(['exito' => false, 'mensaje' => 'Se requiere un token Bearer válido.'], 401);
        }

        if (! in_array($request->user()->role, ['administrador', 'recepcionista', 'cajero'], true)) {
            return response()->json(
                ['error' => 'Permisos insuficientes para ejecutar este método'],
                403,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }

        return $next($request);
    }
}
