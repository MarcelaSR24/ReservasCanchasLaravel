<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoInventario extends Model
{
    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'implemento_id',
        'user_id',
        'tipo_movimiento',
        'cantidad',
        'cantidad_anterior',
        'cantidad_posterior',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'cantidad_anterior' => 'integer',
            'cantidad_posterior' => 'integer',
        ];
    }

    public function implemento(): BelongsTo
    {
        return $this->belongsTo(Implemento::class);
    }

    public function usuarioResponsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
