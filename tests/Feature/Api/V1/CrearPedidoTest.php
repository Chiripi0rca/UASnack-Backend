<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\ErrorNegocio;
use App\Models\CapacidadFranja;
use App\Models\Categorias;
use App\Models\Facultades;
use App\Models\OcupacionFranja;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\CapacidadService;
use App\Services\Stripe\CobrosStripe;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

// #148 (HU-07): POST /pedidos crea el pedido, aparta stock y cocina, y crea el cobro.
// Stripe se simula con CobrosStripe: estas pruebas nunca llaman a la API real.
class CrearPedidoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $alumno;

    private Tienda $tienda;

    private Producto $cafe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        config(['services.stripe.comision_pct' => 0]);

        // Lunes 26 de octubre de 2026, 10:53 en Culiacán
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:53:00', CapacidadService::ZONA_HORARIA));

        $this->alumno = $this->alumno('ana@info.uas.edu.mx');
        $this->tienda = $this->tienda('Cafetería');

        foreach (['11:00', '11:10', '11:20'] as $hora) {
            CapacidadFranja::create([
                'id_tienda' => $this->tienda->id,
                'dia_semana' => Carbon::MONDAY,
                'hora_inicio' => "$hora:00",
                'hora_fin' => Carbon::parse("2026-10-26 $hora:00")->addMinutes(10)->format('H:i:s'),
                'minutos_cocina' => 24,
            ]);
        }

        // 30 pesos y 3 minutos de cocina por pieza
        $this->cafe = $this->producto($this->tienda, 'Café', 10);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --- camino feliz ---

    public function test_crea_el_pedido_aparta_stock_y_cocina_y_regresa_los_datos_de_pago(): void
    {
        $this->cobroSimulado();

        $respuesta = $this->crear($this->cuerpo(2))->assertCreated();

        $respuesta
            ->assertJsonPath('datos.estado', 'PENDIENTE_PAGO')
            ->assertJsonPath('datos.total', '60.00')
            ->assertJsonPath('datos.franja_inicio', '11:20')
            ->assertJsonPath('datos.franja_fin', '11:30')
            ->assertJsonPath('datos.pago.client_secret', 'pi_1_secret_x')
            ->assertJsonPath('datos.pago.stripe_account', 'acct_tienda')
            ->assertJsonMissingPath('datos.pin_retiro');
        $this->assertMatchesRegularExpression('/^TA-\d{4,6}$/', $respuesta->json('datos.codigo_pedido'));

        $pedido = Pedido::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->alumno->id, $pedido->id_usuario);
        $this->assertSame(6, $pedido->minutos_cocina);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $pedido->pin_retiro);
        $this->assertSame([['franja_inicio' => '2026-10-26 11:10:00', 'minutos' => 6]], $pedido->reparto_cocina);

        $detalle = $pedido->detalle()->firstOrFail();
        $this->assertSame(2, $detalle->cantidad);
        $this->assertSame('60.00', $detalle->subtotal);

        $this->assertSame(8, $this->cafe->fresh()->stock_hoy);
        $this->assertSame(6, (int) OcupacionFranja::withoutGlobalScopes()->value('minutos_ocupados'));
        $this->assertSame('pi_1', Pago::firstOrFail()->id_payment_intent);
    }

    public function test_el_total_sale_del_servidor_aunque_la_app_mande_otro(): void
    {
        $this->cobroSimulado();
        $cuerpo = $this->cuerpo(2);
        $cuerpo['total'] = 0.01;
        $cuerpo['lineas'][0]['precio'] = 0.01;

        $this->crear($cuerpo)->assertCreated()->assertJsonPath('datos.total', '60.00');
    }

    public function test_voy_saliendo_no_aparta_cocina_ni_lleva_hora(): void
    {
        $this->cobroSimulado();
        $cuerpo = $this->cuerpo(1);
        $cuerpo['modo_retiro'] = 'voy_saliendo';
        $cuerpo['franja'] = null;

        $this->crear($cuerpo)
            ->assertCreated()
            ->assertJsonPath('datos.franja_inicio', null)
            ->assertJsonPath('datos.franja_fin', null);

        $this->assertSame(0, OcupacionFranja::withoutGlobalScopes()->count());
        $this->assertSame(3, Pedido::withoutGlobalScopes()->firstOrFail()->minutos_cocina);
    }

    // --- clave repetida ---

    public function test_la_misma_clave_regresa_el_mismo_pedido_sin_cobrar_ni_descontar_otra_vez(): void
    {
        $this->mock(CobrosStripe::class, function (MockInterface $stripe) {
            $stripe->shouldReceive('crearIntento')->once()
                ->andReturn(['id' => 'pi_1', 'client_secret' => 'pi_1_secret_x']);
            // el client_secret no se guarda: en la repetición se vuelve a pedir a Stripe
            $stripe->shouldReceive('obtenerIntento')->once()->with('pi_1', 'acct_tienda')
                ->andReturn(['id' => 'pi_1', 'client_secret' => 'pi_1_secret_x']);
        });
        $clave = (string) Str::uuid();

        $primera = $this->crear($this->cuerpo(2), $clave)->assertCreated();
        $segunda = $this->crear($this->cuerpo(2), $clave)->assertOk();

        $this->assertSame($primera->json('datos.codigo_pedido'), $segunda->json('datos.codigo_pedido'));
        $segunda->assertJsonPath('datos.pago.client_secret', 'pi_1_secret_x');
        $this->assertSame(1, Pedido::withoutGlobalScopes()->count());
        $this->assertSame(8, $this->cafe->fresh()->stock_hoy);
        $this->assertSame(1, Pago::count());
    }

    public function test_la_clave_de_otro_alumno_responde_409(): void
    {
        $this->cobroSimulado();
        $clave = (string) Str::uuid();
        $this->crear($this->cuerpo(1), $clave)->assertCreated();

        // actingAs: Laravel recuerda al usuario de la petición anterior (Ana) y ignoraría el token de Beto
        Sanctum::actingAs($this->alumno('beto@info.uas.edu.mx'));
        $this->withHeaders(['Idempotency-Key' => $clave])
            ->postJson('/api/v1/pedidos', $this->cuerpo(1))
            ->assertStatus(409)
            ->assertJsonPath('error.codigo', 'CLAVE_IDEMPOTENCIA_EN_USO');
        $this->assertSame(1, Pedido::withoutGlobalScopes()->count());
    }

    // --- lo que impide crear el pedido ---

    public function test_tienda_sin_cuenta_habilitada_responde_409_y_no_cambia_nada(): void
    {
        $this->tienda->forceFill(['cuenta_habilitada' => false])->save();
        $this->mock(CobrosStripe::class, fn (MockInterface $s) => $s->shouldNotReceive('crearIntento'));

        $this->crear($this->cuerpo(1))
            ->assertStatus(409)
            ->assertJsonPath('error.codigo', 'TIENDA_SIN_PAGOS');

        $this->assertNadaCambio();
    }

    public function test_sin_stock_responde_409_y_no_crea_el_pedido(): void
    {
        $this->cafe->update(['stock_hoy' => 1]);
        $this->mock(CobrosStripe::class, fn (MockInterface $s) => $s->shouldNotReceive('crearIntento'));

        $this->crear($this->cuerpo(2))
            ->assertStatus(409)
            ->assertJsonPath('error.codigo', 'STOCK_INSUFICIENTE');

        $this->assertSame(0, Pedido::withoutGlobalScopes()->count());
        $this->assertSame(1, $this->cafe->fresh()->stock_hoy);
    }

    public function test_hora_que_ya_no_alcanza_responde_409(): void
    {
        $this->cafe->update(['stock_hoy' => 50]);
        $this->mock(CobrosStripe::class, fn (MockInterface $s) => $s->shouldNotReceive('crearIntento'));

        // 20 piezas son 60 min de cocina y antes de las 11:20 solo caben 48
        $this->crear($this->cuerpo(20))
            ->assertStatus(409)
            ->assertJsonPath('error.codigo', 'FRANJA_LLENA');

        $this->assertNadaCambio(50);
    }

    public function test_una_hora_que_la_tienda_no_ofrece_responde_409(): void
    {
        $this->mock(CobrosStripe::class, fn (MockInterface $s) => $s->shouldNotReceive('crearIntento'));
        $cuerpo = $this->cuerpo(1);
        $cuerpo['franja']['inicio'] = '11:03';

        $this->crear($cuerpo)->assertStatus(409)->assertJsonPath('error.codigo', 'FRANJA_LLENA');

        $this->assertNadaCambio();
    }

    public function test_si_falla_el_cobro_se_deshace_todo(): void
    {
        $this->mock(CobrosStripe::class, function (MockInterface $stripe) {
            $stripe->shouldReceive('crearIntento')
                ->once()
                ->andThrow(new ErrorNegocio('STRIPE_NO_DISPONIBLE', 'Stripe no responde.', 502));
        });

        $this->crear($this->cuerpo(2))
            ->assertStatus(502)
            ->assertJsonPath('error.codigo', 'STRIPE_NO_DISPONIBLE');

        // ni pedido, ni stock descontado, ni cocina apartada
        $this->assertNadaCambio();
        $this->assertSame(0, OcupacionFranja::withoutGlobalScopes()->count());
    }

    public function test_productos_de_otra_tienda_responden_422(): void
    {
        $otra = $this->tienda('Papelería', 'acct_otra');
        $ajeno = $this->producto($otra, 'Cuaderno', 5);
        $this->mock(CobrosStripe::class, fn (MockInterface $s) => $s->shouldNotReceive('crearIntento'));
        $cuerpo = $this->cuerpo(1);
        $cuerpo['lineas'][0]['id_producto'] = $ajeno->id;

        $this->crear($cuerpo)->assertStatus(422)->assertJsonPath('error.codigo', 'VARIAS_TIENDAS');
    }

    // --- la petición misma ---

    public function test_sin_la_clave_de_idempotencia_responde_422(): void
    {
        $this->conSesion($this->alumno)
            ->postJson('/api/v1/pedidos', $this->cuerpo(1))
            ->assertStatus(422)
            ->assertJsonPath('error.codigo', 'CLAVE_IDEMPOTENCIA_INVALIDA');
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/pedidos', $this->cuerpo(1))
            ->assertUnauthorized();
    }

    public function test_pedido_sin_productos_o_sin_hora_responde_422(): void
    {
        $sinLineas = $this->cuerpo(1);
        $sinLineas['lineas'] = [];
        $this->crear($sinLineas)->assertStatus(422);

        $sinHora = $this->cuerpo(1);
        unset($sinHora['franja']);
        $this->crear($sinHora)->assertStatus(422);
    }

    // --- Ayudas ---

    // lo que se manda en POST /pedidos: el pedido de $cantidad cafés para recoger a las 11:20
    private function cuerpo(int $cantidad): array
    {
        return [
            'id_tienda' => $this->tienda->id,
            'modo_retiro' => 'franja',
            'franja' => ['fecha' => '2026-10-26', 'inicio' => '11:20'],
            'lineas' => [['id_producto' => $this->cafe->id, 'cantidad' => $cantidad, 'extras' => [], 'quitables' => []]],
        ];
    }

    private function crear(array $cuerpo, ?string $clave = null)
    {
        return $this->conSesion($this->alumno)
            ->withHeaders(['Idempotency-Key' => $clave ?? (string) Str::uuid()])
            ->postJson('/api/v1/pedidos', $cuerpo);
    }

    private function cobroSimulado(): void
    {
        $this->mock(CobrosStripe::class, function (MockInterface $stripe) {
            $stripe->shouldReceive('crearIntento')->once()
                ->andReturn(['id' => 'pi_1', 'client_secret' => 'pi_1_secret_x']);
        });
    }

    // nada se guardó: ni pedido ni pago, y el stock sigue como estaba
    private function assertNadaCambio(int $stock = 10): void
    {
        $this->assertSame(0, Pedido::withoutGlobalScopes()->count());
        $this->assertSame(0, Pago::count());
        $this->assertSame($stock, $this->cafe->fresh()->stock_hoy);
    }

    private function conSesion(Usuario $alumno): static
    {
        return $this->withToken($alumno->createToken('cliente')->plainTextToken);
    }

    private function alumno(string $correo): Usuario
    {
        $alumno = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => $correo,
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);
        $alumno->correo_verificado_en = now();
        $alumno->save();

        return $alumno;
    }

    private function tienda(string $nombre, string $cuentaStripe = 'acct_tienda'): Tienda
    {
        $tienda = Tienda::create([
            'nombre' => $nombre,
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ])->fresh();
        $tienda->forceFill(['id_cuenta_stripe' => $cuentaStripe, 'cuenta_habilitada' => true])->save();

        return $tienda;
    }

    private function producto(Tienda $tienda, string $nombre, int $stock): Producto
    {
        return Producto::create([
            'id_tienda' => $tienda->id,
            'id_categoria' => Categorias::firstOrCreate(['nombre' => 'Bebidas'])->id,
            'nombre' => $nombre,
            'precio' => 30,
            'stock_hoy' => $stock,
            'tiempo_prep_min' => 3,
            'tiempo_prep_max' => 5,
        ]);
    }
}
