<?php

namespace Tests\Feature\Api\V1;

use App\Models\Facultades;
use App\Models\Rol;
use App\Models\Tienda;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Pruebas de administración de tiendas (#224): los endpoints de #102 y los nuevos de #104.
// Lo de PATCH /admin/tiendas/{id}/encargado está en AsignarEncargadoTest.
class TiendaAdminTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $admin;

    private Facultades $miFacultad;

    private Facultades $otraFacultad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        $this->miFacultad = Facultades::firstOrFail();
        $this->otraFacultad = Facultades::create([
            'id_campus' => $this->miFacultad->id_campus,
            'nombre' => 'Facultad de Derecho',
            'clave' => 'DER',
            'activa' => true,
        ]);

        $this->admin = $this->usuario('admin@uas.edu.mx', Rol::ADMINISTRADOR);
    }

    // --- Permisos ---

    public function test_solo_el_administrador_entra(): void
    {
        $alumno = $this->usuario('alumno@uas.edu.mx', Rol::USUARIO);

        foreach (['/api/v1/admin/tiendas', '/api/v1/admin/encargados', '/api/v1/admin/usuarios/buscar?correo=a@uas.edu.mx'] as $ruta) {
            $this->como($alumno)->getJson($ruta)
                ->assertForbidden()
                ->assertJsonPath('error.codigo', 'SIN_PERMISO');
        }
    }

    // --- GET /admin/tiendas (#102) ---

    public function test_lista_las_tiendas_de_su_facultad_incluidas_las_de_baja(): void
    {
        $this->tienda('Activa', $this->miFacultad);
        $this->tienda('De baja', $this->miFacultad, activa: false);
        $this->tienda('Ajena', $this->otraFacultad);

        $this->como($this->admin)->getJson('/api/v1/admin/tiendas')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('datos.0.nombre', 'Activa')
            ->assertJsonPath('datos.1.nombre', 'De baja')
            ->assertJsonPath('datos.1.activa', false)
            ->assertJsonPath('datos.0.encargado', null);
    }

    // --- POST /admin/tiendas (#102) ---

    public function test_crea_la_tienda_en_su_facultad_y_sin_encargado(): void
    {
        $this->como($this->admin)->postJson('/api/v1/admin/tiendas', [
            'nombre' => 'Cafetería nueva',
            'ubicacion' => 'Módulo B',
            'horario_apertura' => '07:00',
            'horario_cierre' => '19:00',
            // Se ignora: la facultad sale del admin, nunca del cliente.
            'id_facultad' => $this->otraFacultad->id,
        ])
            ->assertCreated()
            ->assertJsonPath('datos.nombre', 'Cafetería nueva')
            ->assertJsonPath('datos.id_facultad', $this->miFacultad->id)
            ->assertJsonPath('datos.horario_apertura', '07:00')
            ->assertJsonPath('datos.activa', true)
            ->assertJsonPath('datos.encargado', null);
    }

    public function test_crear_valida_campos_y_horario(): void
    {
        $this->como($this->admin)->postJson('/api/v1/admin/tiendas', [
            'horario_apertura' => '19:00',
            'horario_cierre' => '07:00',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.campos.nombre.0', 'El nombre es obligatorio.')
            ->assertJsonPath('error.campos.horario_cierre.0', 'El horario de cierre debe ser después del de apertura.');
    }

    // --- PATCH /admin/tiendas/{id} (#102) ---

    public function test_dar_de_baja_la_saca_del_catalogo_del_alumno(): void
    {
        $tienda = $this->tienda('Cafetería', $this->miFacultad);
        $alumno = $this->usuario('alumno@uas.edu.mx', Rol::USUARIO);

        $this->como($this->admin)->patchJson("/api/v1/admin/tiendas/{$tienda->id}", ['activa' => false])
            ->assertOk()
            ->assertJsonPath('datos.activa', false);

        $this->app['auth']->forgetGuards();

        $this->como($alumno)->getJson('/api/v1/tiendas')
            ->assertOk()
            ->assertJsonCount(0, 'datos');
    }

    public function test_editar_revisa_el_horario_mezclado_con_lo_que_ya_tenia(): void
    {
        // La tienda abre a las 07:00; mandar solo un cierre a las 06:00 debe fallar.
        $tienda = $this->tienda('Cafetería', $this->miFacultad);

        $this->como($this->admin)->patchJson("/api/v1/admin/tiendas/{$tienda->id}", ['horario_cierre' => '06:00'])
            ->assertStatus(422)
            ->assertJsonPath('error.campos.horario_cierre.0', 'El horario de cierre debe ser después del de apertura.');
    }

    public function test_editar_una_tienda_de_otra_facultad_responde_404(): void
    {
        $ajena = $this->tienda('Ajena', $this->otraFacultad);

        $this->como($this->admin)->patchJson("/api/v1/admin/tiendas/{$ajena->id}", ['nombre' => 'Mía'])
            ->assertNotFound()
            ->assertJsonPath('error.codigo', 'NO_ENCONTRADO');

        $this->assertDatabaseHas('tiendas', ['id' => $ajena->id, 'nombre' => 'Ajena']);
    }

    // --- GET /admin/encargados (#104) ---

    public function test_lista_los_encargados_de_su_facultad_con_su_tienda(): void
    {
        $conTienda = $this->usuario('con.tienda@uas.edu.mx', Rol::ENCARGADO_TIENDA);
        $this->tienda('Cafetería', $this->miFacultad, $conTienda);
        $this->usuario('sin.tienda@uas.edu.mx', Rol::ENCARGADO_TIENDA);
        $this->usuario('alumno@uas.edu.mx', Rol::USUARIO);
        $this->usuario('ajeno@uas.edu.mx', Rol::ENCARGADO_TIENDA, $this->otraFacultad);

        $respuesta = $this->como($this->admin)->getJson('/api/v1/admin/encargados')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $porCorreo = collect($respuesta->json('datos'))->keyBy('correo');
        $this->assertSame('Cafetería', $porCorreo['con.tienda@uas.edu.mx']['tienda']['nombre']);
        $this->assertNull($porCorreo['sin.tienda@uas.edu.mx']['tienda']);
        $this->assertArrayNotHasKey('telefono', $porCorreo['sin.tienda@uas.edu.mx']);
    }

    // --- GET /admin/usuarios/buscar (#104) ---

    public function test_buscar_dice_que_un_usuario_verificado_puede_ser_encargado(): void
    {
        $this->usuario('ana@uas.edu.mx', Rol::USUARIO);

        // El correo se normaliza: mayúsculas y espacios no importan.
        $this->como($this->admin)->getJson('/api/v1/admin/usuarios/buscar?correo='.urlencode(' ANA@uas.edu.mx '))
            ->assertOk()
            ->assertJsonPath('datos.correo', 'ana@uas.edu.mx')
            ->assertJsonPath('datos.rol', 'usuario')
            ->assertJsonPath('datos.puede_ser_encargado', true)
            ->assertJsonPath('datos.motivo', null);
    }

    public function test_buscar_explica_por_que_no_se_puede(): void
    {
        $this->usuario('pendiente@uas.edu.mx', Rol::USUARIO, verificado: false);
        $ocupado = $this->usuario('ocupado@uas.edu.mx', Rol::ENCARGADO_TIENDA);
        $suTienda = $this->tienda('Su tienda', $this->miFacultad, $ocupado);
        $otraTienda = $this->tienda('Otra', $this->miFacultad);

        $casos = [
            ['pendiente@uas.edu.mx', null, 'CORREO_NO_VERIFICADO'],
            ['admin@uas.edu.mx', null, 'ADMIN_NO_PUEDE_SER_ENCARGADO'],
            ['ocupado@uas.edu.mx', $otraTienda->id, 'ENCARGADO_EN_OTRA_TIENDA'],
        ];

        foreach ($casos as [$correo, $idTienda, $codigo]) {
            $this->como($this->admin)
                ->getJson("/api/v1/admin/usuarios/buscar?correo={$correo}&id_tienda={$idTienda}")
                ->assertOk()
                ->assertJsonPath('datos.puede_ser_encargado', false)
                ->assertJsonPath('datos.motivo.codigo', $codigo);
        }

        // Si ya es el encargado de ESA tienda, no cuenta como "otra tienda".
        $this->como($this->admin)
            ->getJson("/api/v1/admin/usuarios/buscar?correo=ocupado@uas.edu.mx&id_tienda={$suTienda->id}")
            ->assertJsonPath('datos.puede_ser_encargado', true)
            ->assertJsonPath('datos.tienda.nombre', 'Su tienda');
    }

    public function test_buscar_un_correo_inexistente_o_invalido(): void
    {
        $this->como($this->admin)->getJson('/api/v1/admin/usuarios/buscar?correo=nadie@uas.edu.mx')
            ->assertNotFound()
            ->assertJsonPath('error.codigo', 'USUARIO_NO_ENCONTRADO');

        $this->como($this->admin)->getJson('/api/v1/admin/usuarios/buscar')
            ->assertStatus(422)
            ->assertJsonPath('error.campos.correo.0', 'Escribe el correo del encargado.');
    }

    private function como(Usuario $usuario): static
    {
        return $this->withToken($usuario->createToken('cliente')->plainTextToken);
    }

    private function usuario(string $correo, string $rol, ?Facultades $facultad = null, bool $verificado = true): Usuario
    {
        $usuario = Usuario::create([
            'nombre' => 'Usuario',
            'apellido' => 'Pruebas',
            'correo_institucional' => $correo,
            'id_facultad' => ($facultad ?? $this->miFacultad)->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        $usuario->asignarRol($rol);
        $usuario->correo_verificado_en = $verificado ? now() : null;
        $usuario->save();

        return $usuario;
    }

    private function tienda(string $nombre, Facultades $facultad, ?Usuario $dueno = null, bool $activa = true): Tienda
    {
        $tienda = Tienda::create([
            'nombre' => $nombre,
            'id_facultad' => $facultad->id,
            'id_dueno' => $dueno?->id,
            'horario_apertura' => '07:00',
            'horario_cierre' => '19:00',
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);

        $tienda->activa = $activa;
        $tienda->save();

        return $tienda;
    }
}