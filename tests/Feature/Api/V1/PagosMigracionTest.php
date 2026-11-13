<?php

namespace Tests\Feature\Api\V1;

use App\Enums\EstadoPago;
use App\Enums\EstadoPedido;
use App\Models\Facultades;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Tienda;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
// Deja la base vacía antes de cada prueba.
use Illuminate\Foundation\Testing\RefreshDatabase;
// La excepción que lanza Laravel cuando se rompe un índice único.
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

// #108 (HU-24): la tabla pagos y las columnas de Stripe existen y se comportan como dice el documento.
class PagosMigracionTest extends TestCase
{
    use RefreshDatabase;

    // Pedido de prueba al que se le cuelgan los pagos.
    private Pedido $pedido;

    // Corre antes de cada prueba: crea facultad, alumno, tienda y pedido.
    protected function setUp(): void
    {
        parent::setUp();

        // Crea universidad, campus y facultad.
        $this->seed(JerarquiaInstitucionalSeeder::class);

        // Alumno de prueba.
        $alumno = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        // Tienda de prueba.
        $tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);

        // Pedido de prueba, esperando su pago.
        $this->pedido = Pedido::create([
            'codigo_pedido' => 'TA-0001',
            'clave_idempotencia' => (string) Str::uuid(),   // un UUID nuevo al azar
            'id_usuario' => $alumno->id,
            'id_tienda' => $tienda->id,
            'estado' => EstadoPedido::PendientePago,
            'modo_retiro' => Pedido::RETIRO_FRANJA,
            'total' => 85,
            'pin_retiro' => '0420',
        ]);
    }

    // Camino feliz: un pago nuevo nace "pendiente" y queda ligado a su pedido.
    public function test_un_pago_nace_pendiente_y_pertenece_a_su_pedido(): void
    {
        // Crea el pago sin decir el estado, para probar el valor por defecto.
        $pago = Pago::create([
            'id_pedido' => $this->pedido->id,
            'id_payment_intent' => 'pi_prueba_1',
            'monto' => 85,
        ]);

        // fresh() vuelve a leer el renglón de la base, con los valores por defecto ya puestos.
        $pago = $pago->fresh();

        // Revisa que el estado sea el enum Pendiente.
        $this->assertSame(EstadoPago::Pendiente, $pago->estado);
        // Revisa que el monto salga con 2 decimales.
        $this->assertSame('85.00', $pago->monto);
        // Revisa que la relación lleve al pedido correcto, en los dos sentidos.
        $this->assertSame($this->pedido->id, $pago->pedido->id);
        $this->assertCount(1, $this->pedido->pagos);
    }

    // Caso borde: el mismo PaymentIntent no se puede guardar dos veces.
    public function test_el_mismo_payment_intent_no_se_guarda_dos_veces(): void
    {
        // Primer pago con pi_repetido: entra sin problema.
        Pago::create(['id_pedido' => $this->pedido->id, 'id_payment_intent' => 'pi_repetido', 'monto' => 85]);

        // Le dice a la prueba que lo que sigue DEBE lanzar esta excepción; si no la lanza, falla.
        $this->expectException(UniqueConstraintViolationException::class);

        // Segundo pago con el mismo pi_repetido: la base lo rechaza.
        Pago::create(['id_pedido' => $this->pedido->id, 'id_payment_intent' => 'pi_repetido', 'monto' => 85]);
    }

    // Una tienda nueva no puede cobrar hasta que Stripe la habilite.
    public function test_una_tienda_nueva_nace_sin_cuenta_habilitada(): void
    {
        // Lee la tienda del pedido desde la base.
        $tienda = $this->pedido->tienda->fresh();

        // Revisa que cuenta_habilitada sea false (gracias al cast, es booleano y no 0).
        $this->assertFalse($tienda->cuenta_habilitada);
        // Revisa que todavía no tenga cuenta de Stripe.
        $this->assertNull($tienda->id_cuenta_stripe);
    }
}