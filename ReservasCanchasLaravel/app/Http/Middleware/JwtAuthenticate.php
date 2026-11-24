<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class JwtAuthenticate
{
    public function handle(Request $request, Closure $next): mixed
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return new JsonResponse(['exito' => false, 'mensaje' => 'Se requiere un token Bearer válido.'], 401);
        }

        try {
            $claims = app(JwtService::class)->validate($token);
        } catch (Throwable) {
            return new JsonResponse(['exito' => false, 'mensaje' => 'El token es inválido, vencido o revocado.'], 401);
        }

        if (array_key_exists('token_use', $claims)
            || array_key_exists('scope', $claims)
            || array_key_exists('aud', $claims)) {
            return new JsonResponse(['exito' => false, 'mensaje' => 'El token no es válido para autenticación REST.'], 401);
        }

        $user = User::query()->find((int) $claims['sub']);
        if (! $user) {
            return new JsonResponse(['exito' => false, 'mensaje' => 'El token es inválido, vencido o revocado.'], 401);
        }

        $request->setUserResolver(static fn (?string $guard = null): User => $user);
        $request->attributes->set('jwt_claims', $claims);
        $request->attributes->set('jwt_token', $token);

        return $next($request);
    }
}
