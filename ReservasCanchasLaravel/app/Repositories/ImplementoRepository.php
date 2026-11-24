<?php

namespace App\Repositories;

use App\Models\Implemento;
use Illuminate\Database\Eloquent\Collection;

class ImplementoRepository
{
    /** @return Collection<int, Implemento> */
    public function all(): Collection
    {
        return Implemento::query()->orderBy('id')->get();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Implemento
    {
        return Implemento::query()->create($attributes);
    }

    public function existsWithCode(string $code): bool
    {
        return Implemento::query()->where('codigo', $code)->exists();
    }

    public function lockForUpdate(int $implementoId): ?Implemento
    {
        return Implemento::query()->whereKey($implementoId)->lockForUpdate()->first();
    }
}
