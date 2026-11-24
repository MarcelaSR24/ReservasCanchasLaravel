<?php

namespace App\Services;

use App\Exceptions\BusinessConflictException;
use App\Exceptions\SoapIntegrationException;
use App\Models\Cobro;
use App\Models\User;
use App\Repositories\CobroRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CobroService
{
    public function __construct(
        private readonly CobroRepository $cobros,
        private readonly SoapReservationAuthorizationService $soapAuthorization,
        private readonly SoapConnectionService $soap,
    ) {}

    /** @param array{reserva_id: int|string, monto: int|float|string} $attributes */
    public function generar(array $attributes, User $actor): Cobro
    {
        try {
            return DB::transaction(function () use ($attributes, $actor): Cobro {
                $reservationId = (int) $attributes['reserva_id'];
                if ($this->cobros->existsForReserva($reservationId)) {
                    throw new BusinessConflictException('La reserva ya tiene un cobro asociado.');
                }

                return $this->cobros->create([
                    'reserva_id' => $reservationId,
                    'monto' => $attributes['monto'],
                    'moneda' => 'COP',
                    'estado' => 'pendiente',
                    'fecha_generacion' => now(),
                    'created_by_user_id' => $actor->getKey(),
                    'updated_by_user_id' => null,
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isReservationUniqueViolation($exception)) {
                throw new BusinessConflictException('La reserva ya tiene un cobro asociado.', previous: $exception);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    public function actualizarEstado(Cobro $cobro, array $attributes, User $actor): Cobro
    {
        $targetState = (string) $attributes['estado'];
        if ($targetState === 'anulado' && $actor->role !== 'administrador') {
            throw new AuthorizationException('Permisos insuficientes para ejecutar este método');
        }

        if (! in_array($targetState, ['pagado', 'anulado'], true)) {
            throw new BusinessConflictException('La transición solicitada no está permitida.');
        }

        return DB::transaction(function () use ($cobro, $attributes, $actor, $targetState): Cobro {
            $lockedCharge = $this->cobros->lockForUpdate((int) $cobro->getKey());
            if (! $lockedCharge) {
                throw new BusinessConflictException('El cobro cambió o dejó de estar disponible.');
            }

            if ($lockedCharge->estado !== 'pendiente') {
                throw new BusinessConflictException('Solo se puede cambiar el estado de un cobro pendiente.');
            }

            $lockedCharge->estado = $targetState;
            $lockedCharge->updated_by_user_id = $actor->getKey();

            if ($targetState === 'pagado') {
                $lockedCharge->metodo_pago = $attributes['metodo_pago'];
                $lockedCharge->referencia_transaccion = $attributes['referencia_transaccion'] ?? null;
                $lockedCharge->fecha_pago = now();
            }

            $lockedCharge->save();

            return $lockedCharge;
        });
    }

    /** @return array{cobro: array<string, mixed>, reserva: array<string, mixed>} */
    public function consultar(Cobro $cobro, Request $request): array
    {
        $normalToken = $request->attributes->get('jwt_token');
        if (! is_string($normalToken)) {
            throw new AuthorizationException('Se requiere un token Bearer válido.');
        }

        $soapToken = $request->user()?->role === 'cajero'
            ? $this->soapAuthorization->issueForAuthorizedCobro($request)
            : $normalToken;

        try {
            $soapResult = $this->soap->consultarReservas($soapToken);
        } catch (Throwable $exception) {
            throw new SoapIntegrationException('No fue posible consultar la reserva asociada al cobro.', previous: $exception);
        }

        $reservations = $soapResult['datos'] ?? null;
        if (($soapResult['exito'] ?? false) !== true || ! is_array($reservations)) {
            throw new SoapIntegrationException('No fue posible consultar la reserva asociada al cobro.');
        }

        $reservation = null;
        foreach ($reservations as $candidate) {
            $reservationId = is_array($candidate) ? ($candidate['id'] ?? null) : (is_object($candidate) ? ($candidate->id ?? null) : null);
            if (is_numeric($reservationId) && (int) $reservationId === (int) $cobro->reserva_id) {
                $reservation = $candidate;
                break;
            }
        }

        if (! is_array($reservation) && ! is_object($reservation)) {
            throw new SoapIntegrationException('El servicio SOAP no devolvió la reserva asociada al cobro.');
        }

        return [
            'cobro' => [
                'id' => $cobro->id,
                'reserva_id' => $cobro->reserva_id,
                'monto' => $cobro->monto,
                'moneda' => $cobro->moneda,
                'estado' => $cobro->estado,
                'metodo_pago' => $cobro->metodo_pago,
                'referencia_transaccion' => $cobro->referencia_transaccion,
                'fecha_generacion' => $cobro->fecha_generacion,
                'fecha_pago' => $cobro->fecha_pago,
            ],
            'reserva' => [
                'id' => data_get($reservation, 'id'),
                'cancha_id' => data_get($reservation, 'cancha_id'),
                'usuario_id' => data_get($reservation, 'usuario_id'),
                'fecha' => data_get($reservation, 'fecha'),
                'hora' => data_get($reservation, 'hora'),
                'estado' => data_get($reservation, 'estado'),
            ],
        ];
    }

    private function isReservationUniqueViolation(QueryException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'cobros_reserva_id_unique')
            || str_contains($message, 'cobros.reserva_id');
    }
}
