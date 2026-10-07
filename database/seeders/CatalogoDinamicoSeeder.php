<?php

namespace Database\Seeders;

use App\Documents\CatalogoDinamico;
use App\Models\Producto;
use Illuminate\Database\Seeder;

class CatalogoDinamicoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Producto::withoutGlobalScopes()->get() as $producto) {
            CatalogoDinamico::updateOrCreate(
                ['id_producto' => $producto->id],
                [
                    'id_tienda' => $producto->id_tienda,
                    'personalizaciones' => [
                        [
                            'tipo' => 'extras',
                            'opciones' => [
                                ['id' => 'queso', 'nombre' => 'Queso extra', 'precio' => 5.00],
                                ['id' => 'aguacate', 'nombre' => 'Aguacate', 'precio' => 8.00],
                            ],
                        ],
                        [
                            'tipo' => 'quitables',
                            'opciones' => [
                                ['id' => 'cebolla', 'nombre' => 'Cebolla'],
                                ['id' => 'chile', 'nombre' => 'Chile'],
                            ],
                        ],
                    ],
                ]
            );
        }
    }
}