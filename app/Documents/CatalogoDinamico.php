<?php

namespace App\Documents;

use MongoDB\Laravel\Eloquent\Model;

class CatalogoDinamico extends Model
{
    protected $connection = 'mongodb'; //se establece la conexion
    protected $table = 'catalogo_dinamico'; //se guarda el nombre de la coleccion

    protected $fillable = [
        'id_producto',
        'id_tienda',
        'personalizaciones',
    ];

    protected $casts = [
        'id_producto' => 'integer',
        'id_tienda' => 'integer',
    ];
}
