<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Producto;

class ProductoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $productos = [
            [
                'id_tienda' => 1,
                'id_categoria' => 1,
                'nombre' => 'Papas fritas',
                'descripcion' => 'Papas fritas con sal',
                'precio' => 30.00,
                'stock_hoy' => 50,
                'tiempo_prep_min' => 5,
                'tiempo_prep_max' => 10,
            ],
            [
                'id_tienda' => 1,
                'id_categoria' => 1,
                'nombre' => 'Te beso negro',
                'descripcion' => 'Te que sabe a te',
                'precio' => 30.00,
                'stock_hoy' => 50,
                'tiempo_prep_min' => 5,
                'tiempo_prep_max' => 10,
            ],
            [
                'id_tienda' => 2,
                'id_categoria' => 2,
                'nombre' => 'Pan pizza con papas',
                'descripcion' => 'Pan pizza recien caliente acompañado con unas papas fritas',
                'precio' => 45.00,
                'stock_hoy' => 30,
                'tiempo_prep_min' => 5,
                'tiempo_prep_max' => 12,
            ],
            [
                'id_tienda' => 2,
                'id_categoria' => 3,
                'nombre' => 'Café frio',
                'descripcion' => 'Café frio, 12 oz',
                'precio' => 35.00,
                'stock_hoy' => 100,
                'tiempo_prep_min' => 8,
                'tiempo_prep_max' => 15,
            ],
        ];
        foreach ($productos as $producto) {
            Producto::create($producto);
        }
    }
}
