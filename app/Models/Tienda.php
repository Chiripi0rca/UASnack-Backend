<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    // Relaciones 
    // El cast de "activa" a booleano lo agregala tarea 101 junto con su migración.

    public function facultad(): BelongsTo
    {
        return $this->belongsTo(Facultades::class, 'id_facultad');
    }

    // El encargado de la tienda (rol encargado_tienda)
    public function dueno(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_dueno');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'id_tienda');
    }
}
