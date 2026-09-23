<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Descuento extends Model
{
    protected $table = 'descuentos';
    public $timestamps = false;
    protected $fillable = [
        'id_tienda',
        'id_producto',
        'id_categoria',
        'porcentaje',
        'hora_inicio',
        'hora_fin',
        'dias_semana',
        'vigente_desde',
        'vigente_hasta',
        'activo'
    ];
}
