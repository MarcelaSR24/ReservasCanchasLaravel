<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

class SoapReservationAuthorizationService
{
    private const CAPABILITY_USE = 'soap_capability';

    private const CAPABILITY_SCOPE = 'soap:consultarReservas';

    private const CAPABILITY_AUDIENCE = 'urn:ReservasCanchas#consultarReservas';

    private const CAPABILITY_MAX_TTL_SECONDS = 30;

    public function __construct(
        private readonly JwtService $jwt,
        private readonly SoapReservationCapabilityStore $capabilities,
    ) {}

    /** Issue only from the authenticated cashier path that will implement consultarCobro. */
    public function issueForAuthorizedCobro(Request $request): string
    {
        return $this->jwt->issueSoapReservationCapability($request)['token'];
    }

    public function mayConsult(string $token): bool
    {
        try {
            $claims = $this->jwt->validate($token);
            $user = User::query()->find((int) $claims['sub']);

            if (! $user) {
                return false;
            }

            $hasCapabilityClaims = array_key_exists('token_use', $claims)
                || array_key_exists('scope', $claims)
                || array_key_exists('aud', $claims);

            if (! $hasCapabilityClaims) {
                return in_array($user->role, ['administrador', 'recepcionista'], true);
            }

            if ($user->role !== 'cajero'
                || ($claims['token_use'] ?? null) !== self::CAPABILITY_USE
                || ($claims['scope'] ?? null) !== self::CAPABILITY_SCOPE
                || ($claims['aud'] ?? null) !== self::CAPABILITY_AUDIENCE
                || ! is_numeric($claims['iat'] ?? null)
                || ! is_numeric($claims['exp'] ?? null)
                || (int) $claims['exp'] <= time()
                || (int) $claims['exp'] <= (int) $claims['iat']
                || (int) $claims['exp'] - (int) $claims['iat'] > self::CAPABILITY_MAX_TTL_SECONDS) {
                return false;
            }

            return $this->capabilities->consume((string) $claims['jti'], (int) $claims['exp']);
        } catch (Throwable) {
            return false;
        }
    }
}
