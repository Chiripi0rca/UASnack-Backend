<?php

// Carpeta (namespace) donde viven las pruebas de la API v1.
namespace Tests\Feature\Api\V1;

// El error del proyecto, para simular que Stripe falló.
use App\Exceptions\ErrorNegocio;
// Modelo de facultades: el alumno de prueba necesita una.
use App\Models\Facultades;
// Modelo del usuario (el alumno).
use App\Models\Usuario;
// La clase que habla con Stripe; en las pruebas se reemplaza por una falsa.
use App\Services\Stripe\TarjetasStripe;
// Seeder que crea universidad, campus y facultad en la base de pruebas.
use Database\Seeders\JerarquiaInstitucionalSeeder;
// Deja la base de datos vacía antes de cada prueba.
use Illuminate\Foundation\Testing\RefreshDatabase;
// Para guardar la contraseña con hash, igual que en el registro real.
use Illuminate\Support\Facades\Hash;
// Tipo del objeto falso (mock) que reemplaza a TarjetasStripe.
use Mockery\MockInterface;
// La clase de Stripe para una tarjeta; con ella se arman tarjetas falsas.
use Stripe\PaymentMethod;
// Clase base de todas las pruebas del proyecto.
use Tests\TestCase;

// #188 (HU-08): GET /pagos/metodos regresa las tarjetas guardadas del alumno.
// Stripe se simula con TarjetasStripe: estas pruebas nunca llaman a la API real.
class MetodosPagoTest extends TestCase
{
    // Activa el borrado de la base antes de cada prueba, para que una no afecte a otra.
    use RefreshDatabase;

    // Aquí se guarda el alumno de prueba para usarlo en todas las pruebas.
    private Usuario $alumno;

    // setUp corre antes de CADA prueba: prepara lo que todas necesitan.
    protected function setUp(): void
    {
        // Prepara Laravel para la prueba (siempre va primero).
        parent::setUp();

        // Llena la base con universidad, campus y facultad.
        $this->seed(JerarquiaInstitucionalSeeder::class);

        // Crea al alumno de prueba directo en la base.
        $this->alumno = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id, // la primera facultad que creó el seeder
            'hash_password' => Hash::make('secreta123'),     // guarda la contraseña con hash
            'telefono' => '6671234567',
        ]);
    }

    // Prueba 1: sin token no se puede ver ninguna tarjeta.
    public function test_sin_sesion_responde_401(): void
    {
        // Hace GET al endpoint SIN token.
        $this->getJson('/api/v1/pagos/metodos')
            // Revisa que responda 401 (no autenticado)…
            ->assertStatus(401)
            // …y que el JSON traiga error.codigo = NO_AUTENTICADO.
            ->assertJsonPath('error.codigo', 'NO_AUTENTICADO');
    }

    // Prueba 2 (camino feliz): Stripe regresa dos tarjetas y salen con la forma del contrato.
    public function test_regresa_las_tarjetas_con_la_forma_del_contrato(): void
    {
        // Le pone al alumno un cliente de Stripe de mentira.
        // forceFill porque id_cliente_stripe no está en $fillable (nunca viene de la app).
        $this->alumno->forceFill(['id_cliente_stripe' => 'cus_ana'])->save();

        // Reemplaza TarjetasStripe por una versión falsa.
        $this->mock(TarjetasStripe::class, function (MockInterface $stripe) {
            // tarjetasDe debe llamarse una sola vez, con el cliente de ESTE alumno…
            $stripe->shouldReceive('tarjetasDe')
                ->once()
                ->with('cus_ana')
                // …y en vez de ir a Stripe, regresa estas dos tarjetas.
                // constructFrom las arma igual que como las manda Stripe de verdad.
                ->andReturn([
                    PaymentMethod::constructFrom([
                        'id' => 'pm_visa',
                        'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 3, 'exp_year' => 2028],
                    ]),
                    PaymentMethod::constructFrom([
                        'id' => 'pm_master',
                        'card' => ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 11, 'exp_year' => 2030],
                    ]),
                ]);
        });

        // Hace GET al endpoint con un token real del alumno (igual que las otras pruebas del repo).
        $this->withToken($this->alumno->createToken('cliente')->plainTextToken)
            ->getJson('/api/v1/pagos/metodos')
            // Revisa que responda 200.
            ->assertOk()
            // Revisa que el JSON sea EXACTAMENTE este (ni un campo de más ni de menos).
            // Fíjate en "vence": 3/2028 sale "03/28" y 11/2030 sale "11/30".
            ->assertExactJson(['datos' => [
                ['id' => 'pm_visa', 'marca' => 'visa', 'ultimos4' => '4242', 'vence' => '03/28'],
                ['id' => 'pm_master', 'marca' => 'mastercard', 'ultimos4' => '4444', 'vence' => '11/30'],
            ]]);
    }

    // Prueba 3 (caso borde): sin cliente de Stripe responde lista vacía y ni siquiera le pregunta a Stripe.
    public function test_sin_cliente_de_stripe_responde_lista_vacia_sin_llamar_a_stripe(): void
    {
        // Esta vez el alumno NO tiene id_cliente_stripe.
        // shouldNotReceive: si el código llegara a llamar a tarjetasDe, la prueba falla.
        $this->mock(TarjetasStripe::class, fn (MockInterface $stripe) => $stripe->shouldNotReceive('tarjetasDe'));

        // Hace GET con el token del alumno y revisa que responda 200 con la lista vacía.
        $this->withToken($this->alumno->createToken('cliente')->plainTextToken)
            ->getJson('/api/v1/pagos/metodos')
            ->assertOk()
            ->assertExactJson(['datos' => []]);
    }

    // Prueba 4: si Stripe falla, la app recibe el error con el formato de siempre.
    public function test_si_stripe_falla_responde_502_con_el_formato_de_error(): void
    {
        // El alumno sí tiene cliente de Stripe, para que el código intente llamarlo.
        $this->alumno->forceFill(['id_cliente_stripe' => 'cus_ana'])->save();

        // La versión falsa de TarjetasStripe lanza el mismo error que lanzaría si Stripe no contestara.
        $this->mock(TarjetasStripe::class, function (MockInterface $stripe) {
            $stripe->shouldReceive('tarjetasDe')->andThrow(
                new ErrorNegocio('STRIPE_NO_DISPONIBLE', 'No pudimos conectar con el servicio de pagos.', 502)
            );
        });

        // Hace GET y revisa que responda 502 con el código STRIPE_NO_DISPONIBLE.
        $this->withToken($this->alumno->createToken('cliente')->plainTextToken)
            ->getJson('/api/v1/pagos/metodos')
            ->assertStatus(502)
            ->assertJsonPath('error.codigo', 'STRIPE_NO_DISPONIBLE');
    }
}