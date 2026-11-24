<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Implemento extends Model
{
    protected $fillable = [
        'codigo',
        'nombre',
        'categoria',
        'descripcion',
        'cantidad_disponible',
        'estado',
        'ubicacion',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_disponible' => 'integer',
        ];
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
    }
}
