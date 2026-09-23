<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Categorias;

class CategoriaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categorias = [
            [
                'nombre' => 'Bebidas',
                'icono_url' => 'https://res.cloudinary.com/dxjv8qg0f/image/upload/v1694861234/uasnack/categorias/bebidas.jpg',
            ],
            [
                'nombre' => 'Botanas',
                'icono_url' => 'https://res.cloudinary.com/dxjv8qg0f/image/upload/v1694861234/uasnack/categorias/botanas.jpg',
            ],
            [
                'nombre' => 'Dulces',
                'icono_url' => 'https://res.cloudinary.com/dxjv8qg0f/image/upload/v1694861234/uasnack/categorias/dulces.jpg',
            ],
            [
                'nombre' => 'Comida rápida',
                'icono_url' => 'https://res.cloudinary.com/dxjv8qg0f/image/upload/v1694861234/uasnack/categorias/comida_rapida.jpg',
            ],
        ];

        foreach ($categorias as $categoria) {
            Categorias::create($categoria);
        }
    }
}
