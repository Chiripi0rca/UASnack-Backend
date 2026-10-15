<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Forma del producto en el panel del encargado.
class ProductoPanelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'id_categoria'    => $this->id_categoria,
            'nombre'          => $this->nombre,
            'descripcion'     => $this->descripcion,
            // numeric de Postgres llega como texto ("30.00"); (float) lo vuelve número
            'precio'          => (float) $this->precio,
            'stock_hoy'       => $this->stock_hoy,
            'tiempo_prep_min' => $this->tiempo_prep_min,
            'tiempo_prep_max' => $this->tiempo_prep_max,
            'foto_url'        => $this->foto_url,
            // Si el modelo castea estado_producto a enum, ->value saca el texto;
            // si es texto plano, lo deja igual.
            'estado_producto' => $this->estado_producto instanceof \BackedEnum
                ? $this->estado_producto->value
                : $this->estado_producto,
        ];
    }
}