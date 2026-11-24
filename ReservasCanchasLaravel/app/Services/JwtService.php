<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class JwtService
{
    private const SOAP_RESERVATION_CAPABILITY_TTL_SECONDS = 30;

    /** @return array{token: string, claims: array<string, mixed>} */
    public function issue(int $userId): array
    {
        $now = time();
        $ttlMinutes = (int) config('jwt.ttl', 60);

        if ($ttlMinutes < 1) {
            throw new RuntimeException('JWT_TTL debe ser mayor que cero.');
        }

        $claims = [
            'iss' => $this->issuer(),
            'sub' => (string) $userId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + ($ttlMinutes * 60),
            'jti' => (string) Str::uuid(),
        ];

        return [
            'token' => JWT::encode($claims, $this->secret(), 'HS256', null, ['typ' => 'JWT']),
            'claims' => $claims,
        ];
    }

    /** @return array{token: string, claims: array<string, mixed>} */
    public function issueSoapReservationCapability(Request $request): array
    {
        $user = $request->user();
        $authenticatedClaims = $request->attributes->get('jwt_claims');
        $authenticatedToken = $request->attributes->get('jwt_token');

        if (! $user
            || $user->role !== 'cajero'
            || ! $request->routeIs('api.cobros.consultar')
            || ! is_array($authenticatedClaims)
            || (string) ($authenticatedClaims['sub'] ?? '') !== (string) $user->getKey()
            || ! is_string($authenticatedToken)
            || ! hash_equals($authenticatedToken, (string) $request->bearerToken())
            || array_key_exists('token_use', $authenticatedClaims)) {
            throw new AuthorizationException('La credencial temporal solo puede emitirse desde consultarCobro autorizado.');
        }

        $now = time();
        $claims = [
            'iss' => $this->issuer(),
            'sub' => (string) $user->getKey(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::SOAP_RESERVATION_CAPABILITY_TTL_SECONDS,
            'jti' => (string) Str::uuid(),
            'token_use' => 'soap_capability',
            'scope' => 'soap:consultarReservas',
            'aud' => 'urn:ReservasCanchas#consultarReservas',
        ];

        return [
            'token' => JWT::encode($claims, $this->secret(), 'HS256', null, ['typ' => 'JWT']),
            'claims' => $claims,
        ];
    }

    /** @return array<string, mixed> */
    public function validate(string $token): array
    {
        if ($token === '' || strlen($token) > 8192 || preg_match('/\s/', $token)) {
            throw new RuntimeException('El token JWT está vacío o tiene un formato inválido.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new RuntimeException('El token JWT tiene un formato inválido.');
        }

        $decoded = JWT::decode($token, new Key($this->secret(), 'HS256'));
        $claims = get_object_vars($decoded);

        if (($claims['iss'] ?? null) !== $this->issuer()) {
            throw new RuntimeException('El emisor del token JWT no es válido.');
        }

        if (! isset($claims['sub']) || ! ctype_digit((string) $claims['sub']) || (int) $claims['sub'] < 1) {
            throw new RuntimeException('El token JWT no identifica a un usuario válido.');
        }

        if (! isset($claims['iat'], $claims['exp'], $claims['jti'])
            || ! is_numeric($claims['iat'])
            || ! is_numeric($claims['exp'])
            || ! is_string($claims['jti'])
            || ! Str::isUuid($claims['jti'])
            || (int) $claims['exp'] <= (int) $claims['iat']
            || (int) $claims['iat'] > time() + 60) {
            throw new RuntimeException('El token JWT no contiene los claims requeridos.');
        }

        if (Cache::has($this->revocationKey($claims['jti']))) {
            throw new RuntimeException('El token JWT fue revocado.');
        }

        return $claims;
    }

    /** @param array<string, mixed> $claims */
    public function revoke(array $claims): void
    {
        $jti = $claims['jti'] ?? null;
        $expiresAt = $claims['exp'] ?? null;

        if (! is_string($jti) || ! Str::isUuid($jti) || ! is_numeric($expiresAt)) {
            throw new RuntimeException('No es posible revocar un token sin jti y exp válidos.');
        }

        Cache::put($this->revocationKey($jti), true, max(1, (int) $expiresAt - time()));
    }

    private function secret(): string
    {
        $secret = (string) config('jwt.secret');
        $appKey = (string) config('app.key');
        $normalizedAppKey = str_starts_with($appKey, 'base64:')
            ? base64_decode(substr($appKey, 7), true)
            : $appKey;

        if (strlen($secret) < 32) {
            throw new RuntimeException('Configura JWT_SECRET con al menos 32 caracteres aleatorios.');
        }

        if ($normalizedAppKey !== false && hash_equals($normalizedAppKey, $secret)) {
            throw new RuntimeException('JWT_SECRET debe ser independiente de APP_KEY.');
        }

        if (strtoupper((string) config('jwt.algorithm', 'HS256')) !== 'HS256') {
            throw new RuntimeException('La aplicación solo permite el algoritmo HS256.');
        }

        return $secret;
    }

    private function issuer(): string
    {
        $issuer = (string) config('jwt.issuer');
        if ($issuer === '') {
            throw new RuntimeException('JWT_ISSUER debe estar configurado.');
        }

        return $issuer;
    }

    private function revocationKey(string $jti): string
    {
        return 'jwt:revoked:'.$jti;
    }
}
