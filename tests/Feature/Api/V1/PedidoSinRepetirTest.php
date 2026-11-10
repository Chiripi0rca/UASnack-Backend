<?php

namespace Tests\Feature\Api\V1;

use App\Enums\EstadoPedido;
use App\Exceptions\ErrorNegocio;
use App\Models\Facultades;
use App\Models\Pedido;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\PedidoService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

// #74 (HU-11): un pedido no se puede repetir con la misma clave de idempotencia.
// CA-01: reenviar la misma confirmación no crea un segundo pedido ni un segundo cobro.
class PedidoSinRepetirTest extends TestCase
{
    use RefreshDatabase;

    private PedidoService $pedidos;

    private Usuario $alumno;

    private Tienda $tienda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->pedidos = app(PedidoService::class);
        $this->alumno = $this->alumno('ana@info.uas.edu.mx');
        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);

        // Ruta solo para estas pruebas: así se prueba el middleware sin esperar a POST /pedidos (#148)
        Route::post('/api/v1/_prueba/idempotencia', fn (Request $request) => response()->json([
            'clave' => $request->attributes->get('clave_idempotencia'),
        ]))->middleware('idempotencia');
    }

    // --- pedidoPorClave ---

    public function test_una_clave_nueva_no_encuentra_pedido(): void
    {
        $this->assertNull($this->pedidos->pedidoPorClave((string) Str::uuid(), $this->alumno));
    }

    public function test_la_misma_clave_regresa_el_pedido_que_ya_se_creo(): void
    {
        $pedido = $this->pedido('5f0c8a1e-6b2d-4c3a-9e7f-1a2b3c4d5e6f');

        $encontrado = $this->pedidos->pedidoPorClave('5f0c8a1e-6b2d-4c3a-9e7f-1a2b3c4d5e6f', $this->alumno);

        $this->assertSame($pedido->id, $encontrado->id);
    }

    // Mayúsculas y minúsculas son el mismo UUID
    public function test_la_clave_en_mayusculas_encuentra_el_mismo_pedido(): void
    {
        $pedido = $this->pedido('5f0c8a1e-6b2d-4c3a-9e7f-1a2b3c4d5e6f');

        $encontrado = $this->pedidos->pedidoPorClave('5F0C8A1E-6B2D-4C3A-9E7F-1A2B3C4D5E6F', $this->alumno);

        $this->assertSame($pedido->id, $encontrado->id);
    }

    // La clave de otro alumno no le regresa su pedido: sería mostrarle datos ajenos
    public function test_la_clave_de_otro_alumno_responde_409_sin_mostrar_su_pedido(): void
    {
        $clave = (string) Str::uuid();
        $this->pedido($clave);

        try {
            $this->pedidos->pedidoPorClave($clave, $this->alumno('beto@info.uas.edu.mx'));
            $this->fail('Debió lanzar CLAVE_IDEMPOTENCIA_EN_USO');
        } catch (ErrorNegocio $e) {
            $this->assertSame('CLAVE_IDEMPOTENCIA_EN_USO', $e->codigo);
            $this->assertSame(409, $e->status);
        }
    }

    // --- sinRepetirClave ---

    public function test_con_una_clave_nueva_crea_el_pedido(): void
    {
        $clave = (string) Str::uuid();

        $creado = $this->pedidos->sinRepetirClave($clave, $this->alumno, fn () => $this->pedido($clave));

        $this->assertSame($clave, $creado->clave_idempotencia);
        $this->assertSame(1, Pedido::count());
    }

    // Dos peticiones al mismo tiempo: la segunda choca con la UNIQUE y recibe el pedido de la primera
    public function test_si_otra_peticion_ya_guardo_la_clave_regresa_ese_pedido_y_no_crea_otro(): void
    {
        $clave = (string) Str::uuid();
        $primero = $this->pedido($clave);

        $segundo = $this->pedidos->sinRepetirClave($clave, $this->alumno, fn () => $this->pedido($clave));

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, Pedido::count());
    }

    // Si choca otra restricción (un folio repetido), no se esconde el error
    public function test_si_choca_otra_restriccion_el_error_se_vuelve_a_lanzar(): void
    {
        $this->pedido((string) Str::uuid(), 'TA-0001');

        $this->expectException(UniqueConstraintViolationException::class);

        $clave = (string) Str::uuid();
        $this->pedidos->sinRepetirClave($clave, $this->alumno, fn () => $this->pedido($clave, 'TA-0001'));
    }

    // --- middleware "idempotencia" ---

    public function test_sin_la_clave_responde_422(): void
    {
        $this->postJson('/api/v1/_prueba/idempotencia')
            ->assertStatus(422)
            ->assertJsonPath('error.codigo', 'CLAVE_IDEMPOTENCIA_INVALIDA');
    }

    public function test_una_clave_que_no_es_uuid_responde_422(): void
    {
        $this->postJson('/api/v1/_prueba/idempotencia', [], ['Idempotency-Key' => 'pedido-1'])
            ->assertStatus(422)
            ->assertJsonPath('error.codigo', 'CLAVE_IDEMPOTENCIA_INVALIDA');
    }

    public function test_una_clave_valida_pasa_en_minusculas(): void
    {
        $this->postJson('/api/v1/_prueba/idempotencia', [], ['Idempotency-Key' => '5F0C8A1E-6B2D-4C3A-9E7F-1A2B3C4D5E6F'])
            ->assertOk()
            ->assertExactJson(['clave' => '5f0c8a1e-6b2d-4c3a-9e7f-1a2b3c4d5e6f']);
    }

    // --- Ayudas ---

    private function alumno(string $correo): Usuario
    {
        return Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => $correo,
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);
    }

    private function pedido(string $clave, ?string $codigo = null): Pedido
    {
        return Pedido::create([
            'codigo_pedido' => $codigo ?? 'TA-'.Str::upper(Str::random(6)),
            'clave_idempotencia' => $clave,
            'id_usuario' => $this->alumno->id,
            'id_tienda' => $this->tienda->id,
            'estado' => EstadoPedido::PendientePago,
            'modo_retiro' => Pedido::RETIRO_FRANJA,
            'total' => 45,
            'pin_retiro' => '0420',
        ]);
    }
}
