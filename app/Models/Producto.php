<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $table = 'productos';
    protected $fillable = [
        'id_tienda',
        'id_categoria',
        'nombre',
        'descripcion',
        'precio',
        'disponible',
        'stock_hoy',
        'tiempo_prep_min',
        'tiempo_prep_max',
        'foto_url',
        'estado_producto',
    ];
}
