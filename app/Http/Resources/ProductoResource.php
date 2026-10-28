<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Qué campos de un producto ve el alumno. Recibe el arreglo que arma CatalogoService
// (no un modelo), por eso se lee con $this['campo'] y no con $this->campo.
class ProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'id_tienda' => $this['id_tienda'],
            'id_categoria' => $this['id_categoria'],
            'nombre' => $this['nombre'],
            'descripcion' => $this['descripcion'],
            'foto_url' => $this['foto_url'],
            'precio' => $this['precio'],             // solo para mostrar; el cobro lo calcula el servidor
            'stock_hoy' => $this['stock_hoy'],       // tope de unidades que se pueden pedir
            'tiempo_prep_min' => $this['tiempo_prep_min'],
            'tiempo_prep_max' => $this['tiempo_prep_max'],
            // #237: false = no se cocina (tiempos en 0). La app no muestra tiempo de preparación.
            'se_prepara' => $this['tiempo_prep_min'] > 0,
            'agotado' => $this['agotado'],           // el cliente lo atenúa; nunca se quita de la lista
            'personalizaciones' => $this['personalizaciones'], // extras y quitables para los chips
        ];
    }
}