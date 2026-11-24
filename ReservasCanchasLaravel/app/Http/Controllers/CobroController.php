<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessConflictException;
use App\Exceptions\SoapIntegrationException;
use App\Http\Requests\StoreCobroRequest;
use App\Http\Requests\UpdateCobroStatusRequest;
use App\Models\Cobro;
use App\Models\User;
use App\Services\CobroService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CobroController extends Controller
{
    public function store(StoreCobroRequest $request, CobroService $cobros): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['exito' => false, 'mensaje' => 'Se requiere un token Bearer válido.'], 401);
        }

        try {
            $cobro = $cobros->generar($request->validated(), $actor);
        } catch (BusinessConflictException $exception) {
            return response()->json(['exito' => false, 'mensaje' => $exception->getMessage()], 409);
        }

        return response()->json([
            'exito' => true,
            'mensaje' => 'Cobro generado correctamente.',
            'datos' => $this->datosPublicos($cobro),
        ], 201);
    }

    public function show(Request $request, int $cobro, CobroService $cobros): JsonResponse
    {
        $charge = Cobro::query()->find($cobro);
        if (! $charge) {
            return response()->json(['exito' => false, 'mensaje' => 'Cobro no encontrado.'], 404);
        }

        try {
            $datos = $cobros->consultar($charge, $request);
        } catch (AuthorizationException) {
            return response()->json(['exito' => false, 'mensaje' => 'Se requiere un token Bearer válido.'], 401);
        } catch (SoapIntegrationException $exception) {
            return response()->json(['exito' => false, 'mensaje' => $exception->getMessage()], 502);
        }

        return response()->json(['exito' => true, 'datos' => $datos]);
    }

    public function updateStatus(
        UpdateCobroStatusRequest $request,
        int $cobro,
        CobroService $cobros
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['exito' => false, 'mensaje' => 'Se requiere un token Bearer válido.'], 401);
        }

        try {
            $charge = Cobro::query()->find($cobro);
            if (! $charge) {
                return response()->json(['exito' => false, 'mensaje' => 'Cobro no encontrado.'], 404);
            }

            $updatedCobro = $cobros->actualizarEstado($charge, $request->validated(), $actor);
        } catch (AuthorizationException) {
            return response()->json(
                ['error' => 'Permisos insuficientes para ejecutar este método'],
                403,
                [],
                JSON_UNESCAPED_UNICODE
            );
        } catch (BusinessConflictException $exception) {
            return response()->json(['exito' => false, 'mensaje' => $exception->getMessage()], 409);
        }

        return response()->json([
            'exito' => true,
            'mensaje' => 'Estado del cobro actualizado correctamente.',
            'datos' => $this->datosPublicos($updatedCobro),
        ]);
    }

    /** @return array<string, mixed> */
    private function datosPublicos(Cobro $cobro): array
    {
        return [
            'id' => $cobro->id,
            'reserva_id' => $cobro->reserva_id,
            'monto' => $cobro->monto,
            'moneda' => $cobro->moneda,
            'estado' => $cobro->estado,
            'metodo_pago' => $cobro->metodo_pago,
            'referencia_transaccion' => $cobro->referencia_transaccion,
            'fecha_generacion' => $cobro->fecha_generacion,
            'fecha_pago' => $cobro->fecha_pago,
        ];
    }
}
