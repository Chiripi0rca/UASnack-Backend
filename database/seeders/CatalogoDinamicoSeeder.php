<?php

namespace Database\Seeders;

use App\Documents\CatalogoDinamico;
use App\Models\Producto;
use Illuminate\Database\Seeder;

// Copia a Mongo (catalogo_dinamico) los extras y quitables que cada producto tiene en PostgreSQL.
// Antes inventaba aquí los mismos cuatro para todos; ahora solo copia lo que ya existe en SQL.
class CatalogoDinamicoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Producto::withoutGlobalScopes()->with('opciones')->get() as $producto) {
            CatalogoDinamico::updateOrCreate(
                ['id_producto' => $producto->id],
                [
                    'id_tienda' => $producto->id_tienda,
                    'personalizaciones' => $producto->personalizaciones(),
                ]
            );
        }
    }
}