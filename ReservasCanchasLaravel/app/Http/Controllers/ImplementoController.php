<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessConflictException;
use App\Http\Requests\AdjustImplementoStockRequest;
use App\Http\Requests\StoreImplementoRequest;
use App\Models\Implemento;
use App\Models\User;
use App\Services\ImplementoService;
use App\Services\InventarioService;
use Illuminate\Http\JsonResponse;

class ImplementoController extends Controller
{
    public function index(ImplementoService $implementos): JsonResponse
    {
        $datos = $implementos->listar()->map(fn (Implemento $implemento): array => $this->datosPublicos($implemento))->values();

        return response()->json(['exito' => true, 'datos' => $datos]);
    }

    public function store(StoreImplementoRequest $request, ImplementoService $implementos): JsonResponse
    {
        try {
            $implemento = $implementos->registrar($request->validated());
        } catch (BusinessConflictException $exception) {
            return response()->json(['exito' => false, 'mensaje' => $exception->getMessage()], 409);
        }

        return response()->json([
            'exito' => true,
            'mensaje' => 'Implemento registrado correctamente.',
            'datos' => $this->datosPublicos($implemento),
        ], 201);
    }

    public function ajustarStock(
        AdjustImplementoStockRequest $request,
        int $implemento,
        InventarioService $inventario
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['exito' => false, 'mensaje' => 'Se requiere un token Bearer válido.'], 401);
        }

        try {
            $equipment = Implemento::query()->find($implemento);
            if (! $equipment) {
                return response()->json(['exito' => false, 'mensaje' => 'Implemento no encontrado.'], 404);
            }

            $result = $inventario->ajustar($equipment, $request->validated(), $actor);
        } catch (BusinessConflictException $exception) {
            return response()->json(['exito' => false, 'mensaje' => $exception->getMessage()], 409);
        }

        return response()->json([
            'exito' => true,
            'mensaje' => 'Existencias ajustadas correctamente.',
            'datos' => [
                'implemento' => $this->datosPublicos($result['implemento']),
                'movimiento' => $result['movimiento']->only([
                    'id',
                    'implemento_id',
                    'user_id',
                    'tipo_movimiento',
                    'cantidad',
                    'cantidad_anterior',
                    'cantidad_posterior',
                    'motivo',
                    'created_at',
                    'updated_at',
                ]),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function datosPublicos(Implemento $implemento): array
    {
        return $implemento->only([
            'id',
            'codigo',
            'nombre',
            'categoria',
            'descripcion',
            'cantidad_disponible',
            'estado',
            'ubicacion',
        ]);
    }
}
