<?php

namespace App\Models;

use App\Models\Scopes\DeLaTiendaDelEncargado;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

// Minutos ya apartados en una franja de un día concreto (#105). Solo la escribe CapacidadService.
#[ScopedBy([DeLaTiendaDelEncargado::class])]
class OcupacionFranja extends Model
{
    protected $table = 'ocupacion_franjas';
    public $timestamps = false;
    protected $fillable = [
        'id_tienda',
        'franja_inicio',
        'minutos_ocupados',
    ];

    protected $casts = [
        'franja_inicio' => 'datetime',
    ];
}
