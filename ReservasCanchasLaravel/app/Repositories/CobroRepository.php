<?php

namespace App\Repositories;

use App\Models\Cobro;

class CobroRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Cobro
    {
        return Cobro::query()->create($attributes);
    }

    public function existsForReserva(int $reservaId): bool
    {
        return Cobro::query()->where('reserva_id', $reservaId)->exists();
    }

    public function lockForUpdate(int $cobroId): ?Cobro
    {
        return Cobro::query()->whereKey($cobroId)->lockForUpdate()->first();
    }
}
