<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

class SoapReservationCapabilityStore
{
    public function __construct(private readonly Repository $cache) {}

    public function consume(string $jti, int $expiresAt): bool
    {
        $secondsRemaining = $expiresAt - time();
        if (! Str::isUuid($jti) || $secondsRemaining < 1) {
            return false;
        }

        return $this->cache->add($this->key($jti), true, $secondsRemaining);
    }

    private function key(string $jti): string
    {
        return 'soap:consultarReservas:consumed:'.$jti;
    }
}
