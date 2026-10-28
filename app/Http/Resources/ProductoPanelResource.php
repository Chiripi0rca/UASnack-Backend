<?php

namespace App\Http\Resources;

use App\Models\OpcionProducto;
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
            // #237: la casilla "Se prepara al momento" del formulario del panel
            'se_prepara'      => $this->tiempo_prep_min > 0,
            'foto_url'        => $this->foto_url,
            // Si el modelo castea estado_producto a enum, ->value saca el texto;
            // si es texto plano, lo deja igual.
            'estado_producto' => $this->estado_producto instanceof \BackedEnum
                ? $this->estado_producto->value
                : $this->estado_producto,
            // Opciones propias del producto (HU-04), para editarlas en el panel.
            'extras' => $this->opciones
                ->where('tipo', OpcionProducto::EXTRA)
                ->map(fn (OpcionProducto $o) => ['id' => $o->id, 'nombre' => $o->nombre, 'precio' => (float) $o->precio])
                ->values(),
            'quitables' => $this->opciones
                ->where('tipo', OpcionProducto::QUITABLE)
                ->map(fn (OpcionProducto $o) => ['id' => $o->id, 'nombre' => $o->nombre])
                ->values(),
        ];
    }
}