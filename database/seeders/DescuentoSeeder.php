<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DescuentoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $descuentos = [
            [
                'id_tienda' => 1,
                'porcentaje' => 20,
                'hora_inicio' => '16:00',
                'hora_fin' => '18:00',
                'dias_semana' => json_encode([1, 2, 3, 4, 5]), // lunes a viernes
                'id_categoria' => null,
                'id_producto' => null,
                'vigente_desde' => now()->toDateString(),
                'vigente_hasta' => now()->addMonths(3)->toDateString(),
            ],
            [
                'id_tienda' => 2,
                'porcentaje' => 15,
                'hora_inicio' => '15:00',
                'hora_fin' => '17:00',
                'dias_semana' => json_encode([1, 2, 3, 4, 5]),
                'id_categoria' => null,
                'id_producto' => null,
                'vigente_desde' => now()->toDateString(),
                'vigente_hasta' => now()->addMonths(3)->toDateString(),
            ],
        ]; 
        foreach ($descuentos as $descuento) {
            DB::table('descuentos')->insert($descuento);
        }
    }
}
