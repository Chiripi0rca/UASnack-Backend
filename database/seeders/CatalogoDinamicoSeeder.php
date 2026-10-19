<?php

namespace Database\Seeders;

use App\Jobs\EspejarProductoEnMongo;
use App\Models\Producto;
use Illuminate\Database\Seeder;

// Copia a Mongo (catalogo_dinamico) cada producto de PostgreSQL, con sus extras y quitables.
// Usa el mismo job que el panel (#165), pero sin cola: al terminar el seed, Mongo ya está listo.
class CatalogoDinamicoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Producto::withoutGlobalScopes()->pluck('id') as $idProducto) {
            EspejarProductoEnMongo::dispatchSync($idProducto);
        }
    }
}
