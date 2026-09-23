<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Tienda;

class TiendaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tiendas =[
            [
                'id_facultad' => 1,
                'nombre' => 'Cafeteria de la FIC',
                'id_dueno' => 1,
                'ubicacion' => 'Esta al lado del estacionamineto del modulo A',
                'horario_apertura' => '07:00',
                'horario_cierre' => '19:00',
                'duracion_franja_min' => 10,
                'cupo_bajo_demanda' => 3,
                'umbral_espera_min' => 15,

            ],
            [
                'id_facultad' => 1,
                'nombre' => 'Cafeteria de la FIC 2',
                'id_dueno' => 2,
                'ubicacion' => 'Esta al lado del modulo A cerca del estacionamiento de maestros',
                'horario_apertura' => '08:00',
                'horario_cierre' => '18:00',
                'duracion_franja_min' => 10,
                'cupo_bajo_demanda' => 2,
                'umbral_espera_min' => 10,
            ],
        ];
        foreach ($tiendas as $tienda) {
            Tienda::create($tienda);
        }
    }
}
