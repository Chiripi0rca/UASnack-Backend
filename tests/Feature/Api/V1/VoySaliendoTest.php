<?php

namespace Tests\Feature\Api\V1;

use App\Enums\EstadoPedido;
use App\Exceptions\ErrorNegocio;
use App\Models\Facultades;
use App\Models\Pedido;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\CapacidadService;
use App\Services\PedidoService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

// PedidoService::voySaliendo (#58): un pedido EN_ESPERA entra a cocina y se calcula su espera.
//
// estimarEspera es la #56 (José Ángel) y todavía no existe, así que aquí se simula:
// siempre regresa de 16 a 22 min y guarda con cuántos minutos se le llamó.
class VoySaliendoTest extends TestCase
{
    use RefreshDatabase;

    private PedidoService $pedidos;

    private Usuario $alumno;

    private Tienda $tienda;

    // Los minutos con los que se llamó a estimarEspera, o null si no se llamó.
    private ?int $minutosConsultados = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        // Simula solo estimarEspera; el resto de CapacidadService sigue siendo el real.
        $this->partialMock(CapacidadService::class, function (MockInterface $mock) {
            $mock->shouldReceive('estimarEspera')->andReturnUsing(function (Tienda $tienda, int $minutos) {
                $this->minutosConsultados = $minutos;

                return ['min' => 16, 'max' => 22, 'saturada' => false, 'pedidos_en_cocina' => 3, 'voy_saliendo_disponible' => true];
            });
        });

        // Se pide DESPUÉS de simular: así PedidoService recibe la CapacidadService simulada.
        $this->pedidos = app(PedidoService::class);

        $this->alumno = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    public function test_un_pedido_en_espera_pasa_a_pagado_con_su_estimado(): void
    {
        $pedido = $this->pedido(EstadoPedido::EnEspera, 12);

        $resultado = $this->pedidos->voySaliendo($pedido);

        // Lo que regresa el método...
        $this->assertSame(EstadoPedido::Pagado, $resultado->estado);
        $this->assertSame(22, $resultado->tiempo_estimado_min);
        // ...y lo que quedó guardado en la base.
        $this->assertSame(EstadoPedido::Pagado, $pedido->fresh()->estado);
        $this->assertSame(22, $pedido->fresh()->tiempo_estimado_min);
    }

    public function test_el_estimado_se_calcula_con_los_minutos_de_cocina_del_pedido(): void
    {
        $this->pedidos->voySaliendo($this->pedido(EstadoPedido::EnEspera, 12));

        $this->assertSame(12, $this->minutosConsultados);
    }

    public function test_un_pedido_que_no_esta_en_espera_responde_409_y_no_cambia(): void
    {
        $pedido = $this->pedido(EstadoPedido::Aceptado, 12);

        try {
            $this->pedidos->voySaliendo($pedido);
            $this->fail('Debió lanzar PEDIDO_NO_EN_ESPERA');
        } catch (ErrorNegocio $e) {
            $this->assertSame('PEDIDO_NO_EN_ESPERA', $e->codigo);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(EstadoPedido::Aceptado, $pedido->fresh()->estado);
        // Si el pedido no estaba en espera, ni siquiera se calcula el estimado.
        $this->assertNull($this->minutosConsultados);
    }

    public function test_tocar_dos_veces_no_lo_mete_dos_veces_a_cocina(): void
    {
        $pedido = $this->pedido(EstadoPedido::EnEspera, 12);
        $this->pedidos->voySaliendo($pedido);

        // El segundo toque llega con el mismo pedido que todavía dice EN_ESPERA en memoria,
        // pero el método lo vuelve a leer de la base y ve que ya está en PAGADO.
        $this->expectException(ErrorNegocio::class);
        $this->pedidos->voySaliendo($pedido);
    }

    // Crea un pedido de voy saliendo de Ana en la Cafetería, con el estado y los minutos dados.
    private function pedido(EstadoPedido $estado, int $minutos): Pedido
    {
        return Pedido::create([
            'codigo_pedido' => 'TA-'.Str::upper(Str::random(6)),
            'clave_idempotencia' => (string) Str::uuid(),
            'id_usuario' => $this->alumno->id,
            'id_tienda' => $this->tienda->id,
            'estado' => $estado,
            'modo_retiro' => Pedido::RETIRO_VOY_SALIENDO,
            'total' => 45,
            'minutos_cocina' => $minutos,
            'pin_retiro' => '0420',
        ]);
    }
}
