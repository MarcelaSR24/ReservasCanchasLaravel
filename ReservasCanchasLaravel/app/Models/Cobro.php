<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cobro extends Model
{
    protected $fillable = [
        'reserva_id',
        'monto',
        'moneda',
        'estado',
        'metodo_pago',
        'referencia_transaccion',
        'fecha_generacion',
        'fecha_pago',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_generacion' => 'datetime',
            'fecha_pago' => 'datetime',
        ];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
