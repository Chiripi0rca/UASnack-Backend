<?php

namespace App\Models;

use App\Models\Scopes\DeLaTiendaDelEncargado;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    // Extras y quitables propios de este producto (HU-04).
    public function opciones(): HasMany
    {
        return $this->hasMany(OpcionProducto::class, 'id_producto');
    }

    // Las opciones con la forma que ya leen la PWA y Android (y que se copia a catalogo_dinamico):
    // [{tipo: 'extras', opciones: [{id, nombre, precio}]}, {tipo: 'quitables', opciones: [{id, nombre}]}]
    // El id va como texto porque así lo declara Android (OpcionDto.id: String).
    public function personalizaciones(): array
    {
        $grupos = [
            ['tipo' => 'extras', 'opciones' => []],
            ['tipo' => 'quitables', 'opciones' => []],
        ];

        foreach ($this->opciones->sortBy('id') as $opcion) {
            if ($opcion->tipo === OpcionProducto::EXTRA) {
                $grupos[0]['opciones'][] = [
                    'id' => (string) $opcion->id,
                    'nombre' => $opcion->nombre,
                    'precio' => (float) $opcion->precio,
                ];
            } else {
                $grupos[1]['opciones'][] = [
                    'id' => (string) $opcion->id,
                    'nombre' => $opcion->nombre,
                ];
            }
        }

        // Un grupo vacío no se manda: el cliente solo dibuja los que traen opciones.
        return array_values(array_filter($grupos, fn (array $grupo) => $grupo['opciones'] !== []));
    }
}