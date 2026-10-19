<?php

namespace App\Jobs;

use App\Documents\CatalogoDinamico;
use App\Models\Producto;
use App\Services\CatalogoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

// Copia un producto de PostgreSQL a catalogo_dinamico (Mongo) (#165).
// Va en cola porque Mongo es secundario: si falla, el producto ya quedó guardado en SQL
// y el worker lo reintenta. Nunca al revés (documento general §5.3).
//
// Va en la cola por defecto porque es la que atiende el worker de docker-compose (queue:work).
class EspejarProductoEnMongo implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    // Segundos entre reintentos: 5, 30 y después 60.
    public array $backoff = [5, 30, 60];

    public function __construct(public int $idProducto) {}

    public function handle(): void
    {
        $producto = Producto::withoutGlobalScopes()->with('opciones')->find($this->idProducto);

        // Si lo borraron antes de que corriera el job, ya no hay nada que copiar.
        if (! $producto) {
            CatalogoDinamico::where('id_producto', $this->idProducto)->delete();

            return;
        }

        CatalogoDinamico::updateOrCreate(
            ['id_producto' => $producto->id],
            [
                'id_tienda' => $producto->id_tienda,
                'nombre' => $producto->nombre,
                'precio' => (float) $producto->precio,
                'stock_restante' => $producto->stock_hoy,
                'disponible_hoy' => $producto->stock_hoy > 0,
                'personalizaciones' => $producto->personalizaciones(),
            ]
        );

        // Se vuelve a borrar la caché ya con Mongo al día: si alguien pidió el catálogo entre
        // el guardado en SQL y este job, Redis se quedó con las opciones viejas.
        Cache::forget(CatalogoService::claveCache($producto->id_tienda));
    }
}
