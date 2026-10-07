<?php

namespace App\Models;

use App\Models\Scopes\DeLaTiendaDelEncargado;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

// Si quien pide es un encargado, solo ve los descuentos de su tienda (#163).
#[ScopedBy([DeLaTiendaDelEncargado::class])]
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
