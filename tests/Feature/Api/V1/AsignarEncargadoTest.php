<?php

namespace Tests\Feature\Api\V1;

use App\Enums\RolUsuario;
use App\Models\Facultades;
use App\Models\Tienda;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AsignarEncargadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
    }

    public function test_asigna_usuario_verificado_y_libera_al_anterior(): void
    {
        $admin = $this->crearUsuario(
            RolUsuario::Admin,
            'admin@info.uas.edu.mx'
        );

        $anterior = $this->crearUsuario(
            RolUsuario::Encargado_Tienda,
            'anterior@info.uas.edu.mx'
        );

        $nuevo = $this->crearUsuario(
            RolUsuario::Usuario,
            'nuevo@info.uas.edu.mx'
        );

        $tienda = $this->crearTienda($anterior);

        $this->withToken(
            $admin->createToken('pruebas')->plainTextToken
        )->patchJson(
            "/api/v1/admin/tiendas/{$tienda->id}/encargado",
            ['correo_institucional' => $nuevo->correo_institucional]
        )
            ->assertOk()
            ->assertJsonPath('datos.encargado.id', $nuevo->id)
            ->assertJsonPath('datos.encargado.rol', 'encargado_tienda');

        $this->assertDatabaseHas('tiendas', [
            'id' => $tienda->id,
            'id_dueno' => $nuevo->id,
        ]);

        $this->assertSame(
            RolUsuario::Encargado_Tienda,
            $nuevo->fresh()->rol
        );

        $this->assertSame(
            RolUsuario::Usuario,
            $anterior->fresh()->rol
        );
    }

    public function test_rechaza_usuario_no_verificado(): void
    {
        $admin = $this->crearUsuario(
            RolUsuario::Admin,
            'admin@info.uas.edu.mx'
        );

        $anterior = $this->crearUsuario(
            RolUsuario::Encargado_Tienda,
            'anterior@info.uas.edu.mx'
        );

        $nuevo = $this->crearUsuario(
            RolUsuario::Usuario,
            'pendiente@info.uas.edu.mx',
            false
        );

        $tienda = $this->crearTienda($anterior);

        $this->withToken(
            $admin->createToken('pruebas')->plainTextToken
        )->patchJson(
            "/api/v1/admin/tiendas/{$tienda->id}/encargado",
            ['correo_institucional' => $nuevo->correo_institucional]
        )
            ->assertStatus(422)
            ->assertJsonPath('error.codigo', 'CORREO_NO_VERIFICADO');

        $this->assertDatabaseHas('tiendas', [
            'id' => $tienda->id,
            'id_dueno' => $anterior->id,
        ]);
    }

    public function test_rechaza_administrador_como_encargado(): void
    {
        $admin = $this->crearUsuario(
            RolUsuario::Admin,
            'admin@info.uas.edu.mx'
        );

        $candidato = $this->crearUsuario(
            RolUsuario::Admin,
            'otroadmin@info.uas.edu.mx'
        );

        $anterior = $this->crearUsuario(
            RolUsuario::Encargado_Tienda,
            'anterior@info.uas.edu.mx'
        );

        $tienda = $this->crearTienda($anterior);

        $this->withToken(
            $admin->createToken('pruebas')->plainTextToken
        )->patchJson(
            "/api/v1/admin/tiendas/{$tienda->id}/encargado",
            ['correo_institucional' => $candidato->correo_institucional]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.codigo',
                'ADMIN_NO_PUEDE_SER_ENCARGADO'
            );
    }

    public function test_rechaza_usuario_que_ya_tiene_otra_tienda(): void
    {
        $admin = $this->crearUsuario(
            RolUsuario::Admin,
            'admin@info.uas.edu.mx'
        );

        $anterior = $this->crearUsuario(
            RolUsuario::Encargado_Tienda,
            'anterior@info.uas.edu.mx'
        );

        $candidato = $this->crearUsuario(
            RolUsuario::Encargado_Tienda,
            'ocupado@info.uas.edu.mx'
        );

        $tiendaObjetivo = $this->crearTienda(
            $anterior,
            'Tienda objetivo'
        );

        $otraTienda = $this->crearTienda(
            $candidato,
            'Otra tienda'
        );

        $this->withToken(
            $admin->createToken('pruebas')->plainTextToken
        )->patchJson(
            "/api/v1/admin/tiendas/{$tiendaObjetivo->id}/encargado",
            ['correo_institucional' => $candidato->correo_institucional]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.codigo',
                'ENCARGADO_EN_OTRA_TIENDA'
            );

        $this->assertDatabaseHas('tiendas', [
            'id' => $tiendaObjetivo->id,
            'id_dueno' => $anterior->id,
        ]);

        $this->assertDatabaseHas('tiendas', [
            'id' => $otraTienda->id,
            'id_dueno' => $candidato->id,
        ]);
    }

    public function test_rechaza_correo_inexistente(): void
    {
        $admin = $this->crearUsuario(
            RolUsuario::Admin,
            'admin@info.uas.edu.mx'
        );

        $anterior = $this->crearUsuario(
            RolUsuario::Encargado_Tienda,
            'anterior@info.uas.edu.mx'
        );

        $tienda = $this->crearTienda($anterior);

        $this->withToken(
            $admin->createToken('pruebas')->plainTextToken
        )->patchJson(
            "/api/v1/admin/tiendas/{$tienda->id}/encargado",
            ['correo_institucional' => 'inexistente@info.uas.edu.mx']
        )
            ->assertNotFound()
            ->assertJsonPath(
                'error.codigo',
                'USUARIO_NO_ENCONTRADO'
            );
    }

    private function crearUsuario(
        RolUsuario $rol,
        string $correo,
        bool $verificado = true
    ): Usuario {
        $usuario = Usuario::create([
            'nombre' => 'Usuario',
            'apellido' => 'Pruebas',
            'correo_institucional' => $correo,
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('Pruebas12345'),
            'telefono' => '6671234567',
        ]);

        // Estos campos se asignan explícitamente porque no son fillable.
        $usuario->rol = $rol;

        if ($verificado) {
            $usuario->correo_verificado_en = now();
        }

        $usuario->save();

        return $usuario;
    }

    private function crearTienda(
        Usuario $dueno,
        string $nombre = 'Cafetería de prueba'
    ): Tienda {
        return Tienda::create([
            'nombre' => $nombre,
            'id_facultad' => $dueno->id_facultad,
            'id_dueno' => $dueno->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }
}
