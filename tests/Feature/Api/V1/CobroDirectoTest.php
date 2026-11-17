<?php

// Carpeta (namespace) donde viven las pruebas de la API v1.
namespace Tests\Feature\Api\V1;

// Estados de un pago y de un pedido.
use App\Enums\EstadoPago;
use App\Enums\EstadoPedido;
// El error del proyecto, para revisar su código y su status.
use App\Exceptions\ErrorNegocio;
// Modelos que se crean en la base de pruebas.
use App\Models\Facultades;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Tienda;
use App\Models\Usuario;
// El servicio que se prueba.
use App\Services\PagoService;
// Las clases que hablan con Stripe; en las pruebas se reemplazan por falsas.
use App\Services\Stripe\CobrosStripe;
use App\Services\Stripe\TarjetasStripe;
// Seeder que crea universidad, campus y facultad.
use Database\Seeders\JerarquiaInstitucionalSeeder;
// Deja la base vacía antes de cada prueba.
use Illuminate\Foundation\Testing\RefreshDatabase;
// Para guardar la contraseña con hash.
use Illuminate\Support\Facades\Hash;
// Para generar UUIDs (la clave de idempotencia del pedido).
use Illuminate\Support\Str;
// Mockery::on permite revisar a mano lo que se le manda a un método simulado.
use Mockery;
// Tipo del objeto falso (mock).
use Mockery\MockInterface;
// La clase de Stripe para una tarjeta; con ella se arman tarjetas falsas.
use Stripe\PaymentMethod;
// Clase base de las pruebas.
use Tests\TestCase;

// #61 (HU-07): el cobro nace directo en la cuenta de la tienda y queda un renglón en pagos.
// Stripe se simula con CobrosStripe y TarjetasStripe: estas pruebas nunca llaman a la API real.
class CobroDirectoTest extends TestCase
{
    // Borra la base antes de cada prueba.
    use RefreshDatabase;

    // Datos de prueba que usan todas las pruebas.
    private Usuario $alumno;

    private Tienda $tienda;

    private Pedido $pedido;

    // Corre antes de CADA prueba: crea alumno, tienda con cuenta habilitada y un pedido.
    protected function setUp(): void
    {
        // Prepara Laravel (siempre va primero).
        parent::setUp();

        // Crea universidad, campus y facultad.
        $this->seed(JerarquiaInstitucionalSeeder::class);

        // Sin comisión, salvo en la prueba que la cambia.
        config(['services.stripe.comision_pct' => 0]);

        // Alumno de prueba.
        $this->alumno = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        // Tienda de prueba.
        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);

        // Le pone a la tienda una cuenta de Stripe ya habilitada.
        // forceFill porque esas columnas no están en $fillable (nunca vienen de la app).
        $this->tienda->forceFill(['id_cuenta_stripe' => 'acct_tienda', 'cuenta_habilitada' => true])->save();

        // Pedido de prueba, esperando su pago.
        $this->pedido = Pedido::create([
            'codigo_pedido' => 'TA-2451',
            'clave_idempotencia' => (string) Str::uuid(),
            'id_usuario' => $this->alumno->id,
            'id_tienda' => $this->tienda->id,
            'estado' => EstadoPedido::PendientePago,
            'modo_retiro' => Pedido::RETIRO_FRANJA,
            'total' => 85,
            'pin_retiro' => '0420',
        ]);
    }

    // Prueba 1 (camino feliz): crea el cobro en la cuenta de la tienda y guarda el pago pendiente.
    public function test_crea_el_cobro_en_la_cuenta_de_la_tienda_y_guarda_el_pago_pendiente(): void
    {
        // Reemplaza CobrosStripe por una versión falsa.
        $this->mock(CobrosStripe::class, function (MockInterface $stripe) {
            // crearIntento debe llamarse una vez…
            $stripe->shouldReceive('crearIntento')
                ->once()
                ->with(
                    // …con estos datos (Mockery::on revisa el arreglo con esta función):
                    Mockery::on(fn (array $datos) => $datos['amount'] === 8500          // 85.00 en centavos
                        && $datos['currency'] === 'mxn'                                 // pesos
                        && $datos['metadata']['codigo_pedido'] === 'TA-2451'           // el folio del pedido
                        && ! isset($datos['application_fee_amount'])                   // sin comisión (0 %)
                        && ! isset($datos['payment_method'])),                         // sin tarjeta guardada
                    // …y en la cuenta de la tienda.
                    'acct_tienda',
                )
                // En vez de ir a Stripe, regresa esto.
                ->andReturn(['id' => 'pi_123', 'client_secret' => 'pi_123_secret_abc']);
        });

        // Llama a crearIntento (ver cobrar() al final).
        $respuesta = $this->cobrar();

        // Revisa que regrese exactamente lo que necesita la app.
        $this->assertSame([
            'client_secret' => 'pi_123_secret_abc',
            'stripe_account' => 'acct_tienda',
            'id_payment_intent' => 'pi_123',
        ], $respuesta);

        // sole() trae el único pago de la tabla (y falla si hay 0 o más de 1).
        $pago = Pago::sole();

        // Revisa cada columna del renglón guardado.
        $this->assertSame($this->pedido->id, $pago->id_pedido);
        $this->assertSame('pi_123', $pago->id_payment_intent);
        $this->assertSame('85.00', $pago->monto);
        $this->assertSame('0.00', $pago->comision_plataforma);
        $this->assertSame(EstadoPago::Pendiente, $pago->estado);
    }

    // Prueba 2: con comisión, se aparta para la plataforma y se guarda en pagos.
    public function test_aparta_la_comision_de_la_plataforma(): void
    {
        // Comisión de 5 %: de 8500 centavos son 425.
        config(['services.stripe.comision_pct' => 5]);

        // crearIntento debe recibir application_fee_amount = 425.
        $this->mock(CobrosStripe::class, function (MockInterface $stripe) {
            $stripe->shouldReceive('crearIntento')
                ->once()
                ->with(Mockery::on(fn (array $datos) => ($datos['application_fee_amount'] ?? null) === 425), 'acct_tienda')
                ->andReturn(['id' => 'pi_123', 'client_secret' => 'pi_123_secret_abc']);
        });

        $this->cobrar();

        // Revisa que la comisión quedó en pesos: 4.25.
        $this->assertSame('4.25', Pago::sole()->comision_plataforma);
    }

    // Prueba 3: con tarjeta guardada, se copia a la tienda y la copia se pega al cobro.
    public function test_con_tarjeta_guardada_la_copia_a_la_tienda_y_la_pega_al_cobro(): void
    {
        // El alumno tiene cliente en Stripe.
        $this->alumno->forceFill(['id_cliente_stripe' => 'cus_ana'])->save();

        // Sus tarjetas guardadas: solo pm_visa.
        $this->mock(TarjetasStripe::class, function (MockInterface $stripe) {
            $stripe->shouldReceive('tarjetasDe')
                ->with('cus_ana')
                ->andReturn([PaymentMethod::constructFrom(['id' => 'pm_visa'])]);
        });

        $this->mock(CobrosStripe::class, function (MockInterface $stripe) {
            // Debe copiar pm_visa del cliente cus_ana a la cuenta de la tienda; la copia es pm_copia.
            $stripe->shouldReceive('copiarTarjeta')
                ->once()
                ->with('cus_ana', 'pm_visa', 'acct_tienda')
                ->andReturn('pm_copia');

            // El cobro debe llevar la COPIA, no la original.
            $stripe->shouldReceive('crearIntento')
                ->once()
                ->with(Mockery::on(fn (array $datos) => ($datos['payment_method'] ?? null) === 'pm_copia'), 'acct_tienda')
                ->andReturn(['id' => 'pi_123', 'client_secret' => 'pi_123_secret_abc']);
        });

        // Cobra con la tarjeta guardada pm_visa.
        $this->cobrar('pm_visa');

        // Revisa que se guardó un pago.
        $this->assertSame(1, Pago::count());
    }

    // Prueba 4 (caso borde): con una tarjeta que no es suya responde 404 y no cobra.
    public function test_una_tarjeta_que_no_es_del_alumno_responde_404_y_no_cobra(): void
    {
        // El alumno solo tiene pm_visa.
        $this->alumno->forceFill(['id_cliente_stripe' => 'cus_ana'])->save();
        $this->mock(TarjetasStripe::class, function (MockInterface $stripe) {
            $stripe->shouldReceive('tarjetasDe')->andReturn([PaymentMethod::constructFrom(['id' => 'pm_visa'])]);
        });

        // Ni se copia ni se cobra nada: si se llamara, la prueba falla.
        $this->mock(CobrosStripe::class, function (MockInterface $stripe) {
            $stripe->shouldNotReceive('copiarTarjeta');
            $stripe->shouldNotReceive('crearIntento');
        });

        // try/catch: se espera que lance ErrorNegocio.
        try {
            // Intenta cobrar con la tarjeta de otro.
            $this->cobrar('pm_de_otro');
            // Si llega aquí es que no lanzó el error: la prueba falla.
            $this->fail('Debió lanzar NO_ENCONTRADO');
        } catch (ErrorNegocio $e) {
            // Revisa el código y el status del error.
            $this->assertSame('NO_ENCONTRADO', $e->codigo);
            $this->assertSame(404, $e->status);
        }

        // Revisa que no se guardó ningún pago.
        $this->assertSame(0, Pago::count());
    }

    // Prueba 5: una tienda sin cuenta habilitada no cobra.
    public function test_una_tienda_sin_cuenta_habilitada_no_cobra(): void
    {
        // La tienda pierde su cuenta habilitada.
        $this->tienda->forceFill(['cuenta_habilitada' => false])->save();

        // No debe llamar a Stripe.
        $this->mock(CobrosStripe::class, fn (MockInterface $stripe) => $stripe->shouldNotReceive('crearIntento'));

        try {
            $this->cobrar();
            $this->fail('Debió lanzar TIENDA_SIN_PAGOS');
        } catch (ErrorNegocio $e) {
            $this->assertSame('TIENDA_SIN_PAGOS', $e->codigo);
            $this->assertSame(409, $e->status);
        }

        // Revisa que no se guardó ningún pago.
        $this->assertSame(0, Pago::count());
    }

    // Ayuda: llama a crearIntento con el pedido de prueba por 85.00.
    // app(PagoService::class) lo crea en ese momento, ya con las clases falsas de la prueba.
    private function cobrar(?string $idMetodoPago = null): array
    {
        return app(PagoService::class)->crearIntento($this->alumno, '85.00', $this->tienda, $this->pedido, $idMetodoPago);
    }
}