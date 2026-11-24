<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reserva extends Model
{
    protected $table = 'reservas';

    public $timestamps = false;

    protected $fillable = [
        'cancha_id',
        'usuario_id',
        'fecha',
        'hora',
        'estado',
    ];

    public function cobro(): HasOne
    {
        return $this->hasOne(Cobro::class);
    }
}
