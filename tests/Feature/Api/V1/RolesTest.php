<?php

namespace Tests\Feature\Api\V1;

use App\Models\Facultades;
use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// El rol vive en la tabla roles (antes era el enum RolUsuario).
class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
    }

    public function test_la_migracion_deja_los_tres_roles(): void
    {
        $this->assertEqualsCanonicalizing(
            [Rol::USUARIO, Rol::ENCARGADO_TIENDA, Rol::ADMINISTRADOR],
            Rol::pluck('clave')->all()
        );
    }

    public function test_un_usuario_nuevo_nace_con_el_rol_usuario(): void
    {
        $usuario = $this->usuario();

        $this->assertSame(Rol::USUARIO, $usuario->fresh()->claveRol());
    }

    public function test_el_rol_no_se_puede_mandar_desde_los_datos_del_cliente(): void
    {
        $usuario = $this->usuario(['id_rol' => Rol::idDe(Rol::ADMINISTRADOR)]);

        $this->assertSame(Rol::USUARIO, $usuario->fresh()->claveRol());
    }

    public function test_la_sesion_sigue_mandando_el_rol_como_texto(): void
    {
        $usuario = $this->usuario();
        $usuario->asignarRol(Rol::ENCARGADO_TIENDA);
        $usuario->correo_verificado_en = now();
        $usuario->save();

        $this->withToken($usuario->createToken('cliente')->plainTextToken)
            ->getJson('/api/v1/auth/yo')
            ->assertOk()
            ->assertJsonPath('usuario.rol', 'encargado_tienda');
    }

    private function usuario(array $extra = []): Usuario
    {
        return Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
            ...$extra,
        ]);
    }
}
