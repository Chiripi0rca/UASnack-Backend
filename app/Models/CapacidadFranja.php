<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
