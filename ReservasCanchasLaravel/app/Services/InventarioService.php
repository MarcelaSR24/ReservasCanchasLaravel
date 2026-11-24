<?php

namespace App\Services;

use App\Exceptions\BusinessConflictException;
use App\Models\Implemento;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Repositories\ImplementoRepository;
use App\Repositories\MovimientoInventarioRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class InventarioService
{
    public function __construct(
        private readonly ImplementoRepository $implementos,
        private readonly MovimientoInventarioRepository $movimientos,
    ) {}

    /**
     * @param  array{tipo_movimiento: string, cantidad: int|string, motivo: string}  $attributes
     * @return array{implemento: Implemento, movimiento: MovimientoInventario}
     */
    public function ajustar(Implemento $implemento, array $attributes, User $actor): array
    {
        return DB::transaction(function () use ($implemento, $attributes, $actor): array {
            $lockedEquipment = $this->implementos->lockForUpdate((int) $implemento->getKey());
            if (! $lockedEquipment) {
                throw (new ModelNotFoundException)->setModel(Implemento::class, [$implemento->getKey()]);
            }

            $previousQuantity = (int) $lockedEquipment->cantidad_disponible;
            $quantity = (int) $attributes['cantidad'];
            $nextQuantity = $attributes['tipo_movimiento'] === 'entrada'
                ? $previousQuantity + $quantity
                : $previousQuantity - $quantity;

            if ($nextQuantity < 0) {
                throw new BusinessConflictException('La salida supera la cantidad disponible.');
            }

            $lockedEquipment->cantidad_disponible = $nextQuantity;
            $lockedEquipment->save();

            $movement = $this->movimientos->create([
                'implemento_id' => $lockedEquipment->getKey(),
                'user_id' => $actor->getKey(),
                'tipo_movimiento' => $attributes['tipo_movimiento'],
                'cantidad' => $quantity,
                'cantidad_anterior' => $previousQuantity,
                'cantidad_posterior' => $nextQuantity,
                'motivo' => $attributes['motivo'],
            ]);

            return ['implemento' => $lockedEquipment, 'movimiento' => $movement];
        });
    }
}
