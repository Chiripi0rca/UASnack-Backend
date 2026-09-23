<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\CapacidadFranja;

class CapacidadFranjaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $capacidadFranjas = [
            [
                'id_tienda' => 1,
                'hora_inicio' => '08:00',
                'hora_fin' => '18:00',
                'minutos_cocina'  => 10,
            ],
            [
                'id_tienda' => 2,
                'hora_inicio' => '09:00',
                'hora_fin' => '17:00',
                'minutos_cocina' => 15,

            ],
        ];

        foreach ($capacidadFranjas as $capacidadFranja) {
            foreach ([1,2,3,4,5] as $dia) {
                CapacidadFranja::create([
                'id_tienda' => $capacidadFranja['id_tienda'],
                'dia_semana' => $dia,
                'hora_inicio' => $capacidadFranja['hora_inicio'],
                'hora_fin' => $capacidadFranja['hora_fin'],
                'minutos_cocina' => $capacidadFranja['minutos_cocina'],
                ]);
            }
        }
    }
}
