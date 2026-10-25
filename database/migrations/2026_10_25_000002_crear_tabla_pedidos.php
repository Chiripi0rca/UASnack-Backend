<?php

use App\Enums\EstadoPedido;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pedidos del alumno (T-45). Es dinero: vive en PostgreSQL, y estado_pedidos_live (Mongo)
// es solo su espejo de lectura rápida.
//
// Cardinalidad: un usuario tiene 0..N pedidos y una tienda recibe 0..N pedidos;
// cada pedido es de exactamente un usuario y una tienda.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_pedido');                                // folio visible, TA-2451
            $table->uuid('clave_idempotencia');                             // llega en el header Idempotency-Key
            $table->foreignId('id_usuario')->constrained('usuarios');
            $table->foreignId('id_tienda')->constrained('tiendas');
            $table->string('estado')->default(EstadoPedido::PendientePago->value);
            $table->string('modo_retiro');                                  // franja | voy_saliendo
            $table->decimal('total', 8, 2);
            $table->string('metodo_pago')->default('tarjeta');
            $table->unsignedSmallInteger('minutos_cocina')->default(0);    // Σ tiempo_prep_min × cantidad, congelado al crear
            $table->dateTime('franja_inicio')->nullable();                  // null en voy saliendo
            $table->dateTime('franja_fin')->nullable();
            $table->string('pin_retiro', 4);                                // texto, para no perder un 0 inicial
            $table->unsignedTinyInteger('intentos_pin')->default(0);
            $table->unsignedSmallInteger('tiempo_estimado_min')->nullable();
            $table->string('motivo_rechazo')->nullable();                   // sin_insumos | cierre_anticipado | no_alcanza_hora
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('aceptado_en')->nullable();
            $table->timestamp('listo_en')->nullable();
            $table->timestamp('llego_en')->nullable();
            $table->timestamp('entregado_en')->nullable();
            $table->timestamp('cancelado_en')->nullable();

            $table->unique('codigo_pedido', 'uq_pedidos_codigo');
            $table->unique('clave_idempotencia', 'uq_pedidos_clave_idempotencia');
            $table->index(['id_tienda', 'franja_inicio'], 'idx_pedidos_tienda_franja');
            $table->index(['id_tienda', 'estado'], 'idx_pedidos_tienda_estado');    // cargaPendiente()
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
