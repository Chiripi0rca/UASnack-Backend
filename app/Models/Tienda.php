<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tienda extends Model

{
    CONST CREATED_AT = 'creado_en';
    CONST UPDATED_AT = null;

    protected $table = 'tiendas';
    protected $fillable = [
        'nombre',
        'id_facultad',
        'id_dueno',
        'ubicacion',
        'horario_apertura',
        'horario_cierre',
        'duracion_franja_min',
        'cupo_bajo_demanda',
        'umbral_espera_min',
    ];
}
