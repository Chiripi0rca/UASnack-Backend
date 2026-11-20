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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

// #69 (HU-09): GET /pagos → historial de pagos del alumno.
class HistorialPagosTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $ana;

    private Tienda $tienda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->ana = $this->alumno('ana@info.uas.edu.mx');
        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson('/api/v1/pagos')->assertUnauthorized();
    }

    // Solo los suyos, sin pendientes, del más nuevo al más viejo.
    public function test_regresa_sus_pagos_del_mas_nuevo_al_mas_viejo(): void
    {
        $this->pago($this->ana, 'TA-0001');
        $this->pago($this->ana, 'TA-0002', EstadoPago::Pendiente);        // no sale
        $this->pago($this->ana, 'TA-0003', EstadoPago::Reembolsado);
        $this->pago($this->alumno('beto@info.uas.edu.mx'), 'TA-0004');   // es de otro: no sale

        $this->comoAna()->getJson('/api/v1/pagos')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('datos.0.codigo_pedido', 'TA-0003')
            ->assertJsonPath('datos.0.estado', 'reembolsado')
            ->assertJsonPath('datos.0.tienda', 'Cafetería')
            ->assertJsonPath('datos.1.codigo_pedido', 'TA-0001');
    }

    public function test_regresa_de_20_en_20(): void
    {
        foreach (range(1, 25) as $numero) {
            $this->pago($this->ana, "TA-$numero");
        }

        $this->comoAna()->getJson('/api/v1/pagos')->assertJsonCount(20, 'datos')->assertJsonPath('meta.total', 25);
        $this->comoAna()->getJson('/api/v1/pagos?pagina=2')->assertJsonCount(5, 'datos');
    }

    // --- Ayudas ---

    private function comoAna(): static
    {
        return $this->withToken($this->ana->createToken('cliente')->plainTextToken);
    }

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

    // Un pedido del alumno con su pago en el estado indicado.
    private function pago(Usuario $alumno, string $codigo, EstadoPago $estado = EstadoPago::Pagado): void
    {
        $pedido = Pedido::create([
            'codigo_pedido' => $codigo,
            'clave_idempotencia' => (string) Str::uuid(),
            'id_usuario' => $alumno->id,
            'id_tienda' => $this->tienda->id,
            'estado' => EstadoPedido::Pagado,
            'modo_retiro' => Pedido::RETIRO_FRANJA,
            'total' => 85,
            'pin_retiro' => '0420',
        ]);

        Pago::create(['id_pedido' => $pedido->id, 'id_payment_intent' => "pi_$codigo", 'monto' => 85, 'estado' => $estado]);
    }
}