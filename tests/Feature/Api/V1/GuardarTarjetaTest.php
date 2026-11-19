<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\ErrorNegocio;
use App\Models\Facultades;
use App\Models\Usuario;
use App\Services\Stripe\TarjetasStripe;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Stripe\PaymentMethod;
use Tests\TestCase;

// #65 (HU-08): guardar y quitar tarjetas del alumno.
//   POST   /pagos/metodos      → { datos: { client_secret } }
//   DELETE /pagos/metodos/{id} → 204
class GuardarTarjetaTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/v1/pagos/metodos';

    private Usuario $alumno;

    // Stripe falso. Cada prueba le dice qué debe recibir; si el código le pide algo
    // que la prueba no esperaba, la petición truena y la prueba falla sola.
    private MockInterface $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        $this->alumno = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        // Reemplaza TarjetasStripe por el falso en todas las pruebas.
        $this->stripe = $this->mock(TarjetasStripe::class);
    }

    // --- Guardar ---

    public function test_sin_sesion_responde_401(): void
    {
        $this->postJson(self::RUTA)
            ->assertUnauthorized()
            ->assertJsonPath('error.codigo', 'NO_AUTENTICADO');
    }

    public function test_la_primera_vez_crea_el_cliente_y_regresa_el_client_secret(): void
    {
        $this->stripe->shouldReceive('crearCliente')->once()->andReturn('cus_nuevo');
        $this->stripe->shouldReceive('crearSetupIntent')->once()->with('cus_nuevo')->andReturn('seti_secret');

        $this->comoAlumno()->postJson(self::RUTA)
            ->assertOk()
            ->assertExactJson(['datos' => ['client_secret' => 'seti_secret']]);

        $this->assertSame('cus_nuevo', $this->alumno->fresh()->id_cliente_stripe);
    }

    // Como no se espera crearCliente, si el código intentara crear otro, la prueba falla.
    public function test_si_ya_tiene_cliente_usa_el_mismo(): void
    {
        $this->conCliente('cus_ana');
        $this->stripe->shouldReceive('crearSetupIntent')->once()->with('cus_ana')->andReturn('seti_secret');

        $this->comoAlumno()->postJson(self::RUTA)
            ->assertOk()
            ->assertJsonPath('datos.client_secret', 'seti_secret');
    }

    public function test_si_stripe_falla_responde_502_y_no_guarda_el_cliente(): void
    {
        $this->stripe->shouldReceive('crearCliente')->andThrow($this->stripeCaido());

        $this->comoAlumno()->postJson(self::RUTA)
            ->assertStatus(502)
            ->assertJsonPath('error.codigo', 'STRIPE_NO_DISPONIBLE');

        $this->assertNull($this->alumno->fresh()->id_cliente_stripe);
    }

    // --- Quitar ---

    public function test_quita_una_tarjeta_suya(): void
    {
        $this->conTarjetas('pm_visa');
        $this->stripe->shouldReceive('quitarTarjeta')->once()->with('pm_visa');

        $this->comoAlumno()->deleteJson(self::RUTA.'/pm_visa')->assertNoContent();
    }

    // Como no se espera quitarTarjeta, si el código intentara quitarla, la prueba falla.
    public function test_una_tarjeta_ajena_responde_404_y_no_se_quita(): void
    {
        $this->conTarjetas('pm_visa');

        $this->comoAlumno()->deleteJson(self::RUTA.'/pm_de_otro')
            ->assertNotFound()
            ->assertJsonPath('error.codigo', 'NO_ENCONTRADO');
    }

    // --- Ayudas ---

    // Manda la petición con un token real del alumno (igual que las otras pruebas del repo).
    private function comoAlumno(): static
    {
        return $this->withToken($this->alumno->createToken('cliente')->plainTextToken);
    }

    // Le pone al alumno un cliente de Stripe (forceFill: la columna no está en $fillable).
    private function conCliente(string $idCliente): void
    {
        $this->alumno->forceFill(['id_cliente_stripe' => $idCliente])->save();
    }

    // El alumno tiene cliente en Stripe y estas tarjetas guardadas.
    private function conTarjetas(string ...$ids): void
    {
        $this->conCliente('cus_ana');

        $this->stripe->shouldReceive('tarjetasDe')->with('cus_ana')->andReturn(
            array_map(fn (string $id) => PaymentMethod::constructFrom(['id' => $id]), $ids),
        );
    }

    // El error que lanza TarjetasStripe cuando Stripe no contesta.
    private function stripeCaido(): ErrorNegocio
    {
        return new ErrorNegocio('STRIPE_NO_DISPONIBLE', 'No pudimos conectar con el servicio de pagos.', 502);
    }
}