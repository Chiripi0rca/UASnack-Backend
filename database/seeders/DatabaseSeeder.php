<?php

namespace Database\Seeders;

use App\Models\DominiosInstitucional;
use App\Models\Usuario;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
       $this->call([ // esta clase es para correr los seeders 
            JerarquiaInstitucionalSeeder::class,
            DominiosInstitucionalesSeeder::class,
            UsuarioSeeder::class,
            TiendaSeeder::class,
            CapacidadFranjaSeeder::class,
            CategoriaSeeder::class,
            ProductoSeeder::class,
            CatalogoDinamicoSeeder::class, // copia a Mongo las opciones de cada producto
            DescuentoSeeder::class,
            ]);
        
    }
}