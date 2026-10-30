<?php

namespace Tests\Feature\Api\V1;

use App\Models\CapacidadFranja;
use App\Models\Tienda;
use App\Services\CapacidadService;
use Database\Seeders\CapacidadFranjaSeeder;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Database\Seeders\TiendaSeeder;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Los datos de prueba de capacidad tienen que servir para reservar (#105) y para ver franjas (#52):
// un renglón por franja, no un bloque de todo el día.
class CapacidadFranjaSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([JerarquiaInstitucionalSeeder::class, UsuarioSeeder::class, TiendaSeeder::class]);
    }

    public function test_crea_un_renglon_por_franja(): void
    {
        $this->seed(CapacidadFranjaSeeder::class);

        // Tienda 1: de 08:00 a 18:00 en franjas de 10 min = 60 por día, de lunes a viernes
        $this->assertSame(60 * 5, CapacidadFranja::where('id_tienda', 1)->count());
        $this->assertDatabaseHas('capacidad_franjas', [
            'id_tienda' => 1, 'dia_semana' => 1, 'hora_inicio' => '11:00:00', 'hora_fin' => '11:10:00', 'minutos_cocina' => 20,
        ]);
    }

    public function test_correrlo_dos_veces_no_duplica(): void
    {
        $this->seed(CapacidadFranjaSeeder::class);
        $antes = CapacidadFranja::count();

        $this->seed(CapacidadFranjaSeeder::class);

        $this->assertSame($antes, CapacidadFranja::count());
    }

    public function test_corrige_el_renglon_viejo_de_bloque_completo(): void
    {
        // Lo que dejaba la versión anterior del seeder
        CapacidadFranja::create([
            'id_tienda' => 1, 'dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '18:00:00', 'minutos_cocina' => 10,
        ]);

        $this->seed(CapacidadFranjaSeeder::class);

        $this->assertDatabaseMissing('capacidad_franjas', ['id_tienda' => 1, 'hora_fin' => '18:00:00', 'hora_inicio' => '08:00:00']);
    }

    // Con el bloque viejo, reservar() a las 11:00 respondía FRANJA_LLENA: no encontraba la franja.
    public function test_con_estos_datos_se_puede_reservar_cualquier_franja(): void
    {
        $this->seed(CapacidadFranjaSeeder::class);
        $capacidad = app(CapacidadService::class);
        $tienda = Tienda::findOrFail(1);
        $lunesALas11 = Carbon::parse('2026-10-26 11:00:00');
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:53:00', CapacidadService::ZONA_HORARIA));

        // Para recoger a las 11:10 se cocina a las 11:00
        $capacidad->reservar($tienda, $lunesALas11->copy()->addMinutes(10), 6);
        Carbon::setTestNow();

        $this->assertSame(6, $capacidad->minutosOcupados($tienda, $lunesALas11));
    }
}
