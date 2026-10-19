<?php

namespace Database\Seeders;

use App\Models\DominiosInstitucional;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

// Sin WithoutModelEvents: los seeders deben crear los datos igual que la app.
// Usuario le pone el rol "usuario" en su evento creating; con los eventos apagados
// el usuario se insertaba sin id_rol y Postgres lo rechazaba.
class DatabaseSeeder extends Seeder
{
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