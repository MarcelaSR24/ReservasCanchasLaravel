<?php

namespace App\Repositories;

use App\Models\MovimientoInventario;

class MovimientoInventarioRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): MovimientoInventario
    {
        return MovimientoInventario::query()->create($attributes);
    }
}
