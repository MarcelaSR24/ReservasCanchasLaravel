<?php

namespace App\Services;

use App\Exceptions\BusinessConflictException;
use App\Models\Implemento;
use App\Repositories\ImplementoRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

class ImplementoService
{
    public function __construct(private readonly ImplementoRepository $implementos) {}

    /** @return Collection<int, Implemento> */
    public function listar(): Collection
    {
        return $this->implementos->all();
    }

    /** @param array<string, mixed> $attributes */
    public function registrar(array $attributes): Implemento
    {
        if ($this->implementos->existsWithCode($attributes['codigo'])) {
            throw new BusinessConflictException('Ya existe un implemento con ese código.');
        }

        try {
            return $this->implementos->create([
                'codigo' => $attributes['codigo'],
                'nombre' => $attributes['nombre'],
                'categoria' => $attributes['categoria'],
                'descripcion' => $attributes['descripcion'] ?? null,
                'cantidad_disponible' => $attributes['cantidad_disponible'],
                'estado' => $attributes['estado'] ?? 'activo',
                'ubicacion' => $attributes['ubicacion'] ?? null,
            ]);
        } catch (QueryException $exception) {
            $message = mb_strtolower($exception->getMessage());
            if (str_contains($message, 'implementos.codigo') || str_contains($message, 'implementos_codigo_unique')) {
                throw new BusinessConflictException('Ya existe un implemento con ese código.', previous: $exception);
            }

            throw $exception;
        }
    }
}
