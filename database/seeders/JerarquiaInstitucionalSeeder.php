<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Universidad;
use App\Models\Campus;
use App\Models\Facultades;

class JerarquiaInstitucionalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $universidad = Universidad::create(['nombre' => 'Universidad Autónoma de Sinaloa']);
        $campus = Campus::create(['id_universidad' => $universidad->id, 'nombre' => 'Culiacán']);
        Facultades::create(['id_campus' => $campus->id, 
        'nombre' => 'Facultad de Informática Culiacán',
        'clave' => 'FIC',
        'activa' => true]);
    }
}
