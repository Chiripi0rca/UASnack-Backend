<?php

namespace Tests\Feature\Api\V1;

use App\Models\DominiosInstitucional;
use App\Models\Facultades;
use App\Models\Universidad;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Database\Seeders\DominiosInstitucionalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->seed(DominiosInstitucionalesSeeder::class);
        DominiosInstitucional::create([
            'id_universidad' => Universidad::first()->id,
            'dominio' => 'uas.edu.mx',
            'activo' => true,
        ]);
    }

    public function test_registra_al_alumno_y_responde_el_tiempo_de_reenvio(): void
    {
        $this->postJson('/api/v1/auth/registro', [
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::first()->id,
            'password' => 'secreta123',
            'telefono' => '6671234567',
            'acepto_terminos' => true,
        ])
            ->assertCreated()
            ->assertJsonStructure(['mensaje', 'reenviar_en_segundos']);

        $this->assertDatabaseHas('usuarios', ['correo_institucional' => 'ana@info.uas.edu.mx']);
    }

    public function test_los_datos_invalidos_responden_422_con_el_formato_del_proyecto(): void
    {
        $this->postJson('/api/v1/auth/registro', ['telefono' => '66712'])
            ->assertStatus(422)
            ->assertJsonPath('error.codigo', 'VALIDACION')
            ->assertJsonPath('error.campos.nombre.0', 'Este campo es obligatorio.')
            ->assertJsonPath('error.campos.telefono.0', 'El teléfono debe tener 10 dígitos.');
    }
}
