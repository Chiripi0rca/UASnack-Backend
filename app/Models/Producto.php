<?php

namespace App\Models;

use App\Models\Scopes\DeLaTiendaDelEncargado;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Si quien pide es un encargado, solo ve los productos de su tienda (#163).
#[ScopedBy([DeLaTiendaDelEncargado::class])]
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

    public function tienda(): BelongsTo
    {
        return $this->belongsTo(Tienda::class, 'id_tienda');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categorias::class, 'id_categoria');
    }
}
