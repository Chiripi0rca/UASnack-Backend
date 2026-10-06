<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    // Relaciones 
    // El global scope por tienda lo agrega la tarea 163.

    public function tienda(): BelongsTo
    {
        return $this->belongsTo(Tienda::class, 'id_tienda');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categorias::class, 'id_categoria');
    }
}
