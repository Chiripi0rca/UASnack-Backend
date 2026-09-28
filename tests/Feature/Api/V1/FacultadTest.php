<?php

namespace Tests\Feature\Api\V1;

use App\Models\Campus;
use App\Models\Facultades;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacultadTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_las_facultades_activas_sin_iniciar_sesion(): void
    {
        $this->seed(JerarquiaInstitucionalSeeder::class);

        $this->getJson('/api/v1/facultades')
            ->assertOk()
            ->assertJsonStructure([
                'datos' => [['id', 'nombre', 'clave']],
                'meta' => ['total'],
            ])
            ->assertJsonPath('datos.0.nombre', 'Facultad de Informática Culiacán');
    }

    public function test_no_incluye_las_facultades_inactivas(): void
    {
        $this->seed(JerarquiaInstitucionalSeeder::class);
        Facultades::create([
            'id_campus' => Campus::first()->id,
            'nombre' => 'Facultad dada de baja',
            'clave' => 'FDB',
            'activa' => false,
        ]);

        $this->getJson('/api/v1/facultades')
            ->assertOk()
            ->assertJsonCount(1, 'datos')
            ->assertJsonPath('meta.total', 1);
    }
}
