<?php

namespace Database\Seeders;

use App\Models\Categorias;
use App\Models\OpcionProducto;
use App\Models\Producto;
use Illuminate\Database\Seeder;

class ProductoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // La categoría se busca por nombre: así no depende del orden en que la creó CategoriaSeeder.
        $categoria = fn (string $nombre) => Categorias::where('nombre', $nombre)->value('id');

        // Cada producto trae SUS extras y quitables (HU-04). Un café no lleva aguacate.
        $productos = [
            [
                'id_tienda' => 1,
                'id_categoria' => $categoria('Botanas'),
                'nombre' => 'Papas fritas',
                'descripcion' => 'Papas fritas con sal',
                'precio' => 30.00,
                'stock_hoy' => 50,
                'tiempo_prep_min' => 5,
                'tiempo_prep_max' => 10,
                'extras' => [
                    ['nombre' => 'Queso cheddar', 'precio' => 10.00],
                    ['nombre' => 'Salsa extra', 'precio' => 5.00],
                ],
                'quitables' => ['Sal', 'Chile en polvo'],
            ],
            [
                'id_tienda' => 1,
                'id_categoria' => $categoria('Bebidas'),
                'nombre' => 'Te beso negro',
                'descripcion' => 'Te que sabe a te',
                'precio' => 30.00,
                'stock_hoy' => 50,
                'tiempo_prep_min' => 5,
                'tiempo_prep_max' => 10,
                'extras' => [
                    ['nombre' => 'Miel', 'precio' => 5.00],
                    ['nombre' => 'Limón', 'precio' => 3.00],
                ],
                'quitables' => ['Azúcar', 'Hielo'],
            ],
            [
                'id_tienda' => 2,
                'id_categoria' => $categoria('Comida rápida'),
                'nombre' => 'Pan pizza con papas',
                'descripcion' => 'Pan pizza recien caliente acompañado con unas papas fritas',
                'precio' => 45.00,
                'stock_hoy' => 30,
                'tiempo_prep_min' => 5,
                'tiempo_prep_max' => 12,
                'extras' => [
                    ['nombre' => 'Queso extra', 'precio' => 8.00],
                    ['nombre' => 'Pepperoni extra', 'precio' => 10.00],
                ],
                'quitables' => ['Cebolla', 'Chile'],
            ],
            [
                'id_tienda' => 2,
                'id_categoria' => $categoria('Bebidas'),
                'nombre' => 'Café frio',
                'descripcion' => 'Café frio, 12 oz',
                'precio' => 35.00,
                'stock_hoy' => 100,
                'tiempo_prep_min' => 8,
                'tiempo_prep_max' => 15,
                'extras' => [
                    ['nombre' => 'Shot extra de café', 'precio' => 10.00],
                    ['nombre' => 'Leche de almendra', 'precio' => 8.00],
                    ['nombre' => 'Crema batida', 'precio' => 7.00],
                ],
                'quitables' => ['Azúcar', 'Hielo'],
            ],
        ];

        foreach ($productos as $datos) {
            $producto = Producto::create(collect($datos)->except(['extras', 'quitables'])->all());

            foreach ($datos['extras'] as $extra) {
                $producto->opciones()->create(['tipo' => OpcionProducto::EXTRA, ...$extra]);
            }

            foreach ($datos['quitables'] as $nombre) {
                $producto->opciones()->create(['tipo' => OpcionProducto::QUITABLE, 'nombre' => $nombre]);
            }
        }
    }
}