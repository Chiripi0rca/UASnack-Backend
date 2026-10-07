<?php

namespace App\Models;

use App\Models\Scopes\DeLaTiendaDelEncargado;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

// Si quien pide es un encargado, solo ve las franjas de su tienda (#163).
#[ScopedBy([DeLaTiendaDelEncargado::class])]
class CapacidadFranja extends Model
{
    protected $table = 'capacidad_franjas';
    public $timestamps = false;
    protected $fillable = [
        'id_tienda',
        'dia_semana',
        'hora_inicio',
        'hora_fin',
        'minutos_cocina'
    ];
}
