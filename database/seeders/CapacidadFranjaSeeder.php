<?php

namespace Database\Seeders;

use App\Models\CapacidadFranja;
use App\Models\Tienda;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

// Capacidad de prueba: UN renglón por franja (07:00, 07:10, 07:20...), que es como la leen
// reservar() (#105) y franjasDisponibles() (#52): buscan la franja por su hora de inicio exacta.
// Un solo renglón de 08:00 a 18:00 dejaría con cupo solo la franja de las 08:00.
//
// Se puede volver a correr sin duplicar: updateOrCreate usa la misma llave única de la tabla
// (id_tienda, dia_semana, hora_inicio). Si había un renglón viejo de bloque completo, se corrige.
class CapacidadFranjaSeeder extends Seeder
{
    // Lunes a viernes (0 = domingo, igual que Carbon::dayOfWeek)
    private const DIAS = [1, 2, 3, 4, 5];

    public function run(): void
    {
        // Horario en que cocina cada tienda y cuántas personas cocinan en cada franja (#106).
        // Lo normal en las tiendas de la facultad son 2 personas todo el día.
        $capacidades = [
            ['id_tienda' => 1, 'desde' => '08:00', 'hasta' => '18:00', 'personas' => 2],
            ['id_tienda' => 2, 'desde' => '09:00', 'hasta' => '17:00', 'personas' => 2],
        ];

        foreach ($capacidades as $capacidad) {
            $tienda = Tienda::find($capacidad['id_tienda']);
            if (! $tienda) {
                continue;
            }

            // Las franjas duran lo que diga la tienda (10 minutos por defecto)
            $duracion = $tienda->duracion_franja_min ?: 10;
            $hasta = Carbon::parse($capacidad['hasta']);

            foreach (self::DIAS as $dia) {
                for ($inicio = Carbon::parse($capacidad['desde']); $inicio->copy()->addMinutes($duracion)->lte($hasta); $inicio->addMinutes($duracion)) {
                    CapacidadFranja::withoutGlobalScopes()->updateOrCreate(
                        [
                            'id_tienda' => $tienda->id,
                            'dia_semana' => $dia,
                            'hora_inicio' => $inicio->format('H:i:s'),
                        ],
                        [
                            'hora_fin' => $inicio->copy()->addMinutes($duracion)->format('H:i:s'),
                            // Cada persona aporta la duración de la franja: 2 × 10 = 20 minutos de cocina
                            'minutos_cocina' => $capacidad['personas'] * $duracion,
                        ]
                    );
                }
            }
        }
    }
}
