<?php

namespace Database\Seeders;


use Illuminate\Database\Seeder;
use App\Models\DominiosInstitucional;
use App\Models\Universidad;

class DominiosInstitucionalesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
    $universidad = Universidad::where('nombre', 'Universidad Autónoma de Sinaloa')->firstOrFail();

        DominiosInstitucional::firstOrCreate(
            ['id_universidad' => $universidad->id, 'dominio' => 'uas.edu.mx'],
            ['activo' => true],
        );
    }
      
}
