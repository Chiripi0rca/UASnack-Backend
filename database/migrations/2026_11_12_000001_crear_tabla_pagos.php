<?php

// Importa el enum para usar su valor como estado por defecto.
use App\Enums\EstadoPago;
// Clase base de toda migración de Laravel.
use Illuminate\Database\Migrations\Migration;
// Objeto con el que se describen las columnas de una tabla.
use Illuminate\Database\Schema\Blueprint;
// Fachada para crear, modificar y borrar tablas.
use Illuminate\Support\Facades\Schema;

// Tabla pagos (T-30, #108). Cada intento de cobro con Stripe es un renglón nuevo.
// Nada se edita ni se borra: un reembolso solo cambia el estado (§5.1).
// Cardinalidad: un pedido tiene 0..N pagos; cada pago es de exactamente un pedido.
return new class extends Migration
{
    // up() corre con "php artisan migrate": crea la tabla.
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            // Llave primaria autoincremental (id).
            $table->id();

            // A qué pedido pertenece el pago. constrained('pedidos') crea la llave foránea a pedidos.id,
            // así no puede existir un pago de un pedido que no existe.
            $table->foreignId('id_pedido')->constrained('pedidos');

            // Id del PaymentIntent en Stripe (pi_…). Lo guarda la #61 al crear el cobro.
            $table->string('id_payment_intent');

            // Id del cargo en Stripe (ch_…). Llega después, con el webhook; por eso puede ir vacío.
            $table->string('id_charge')->nullable();

            // Cuánto se cobró. 8 dígitos en total, 2 decimales (igual que pedidos.total).
            $table->decimal('monto', 8, 2);

            // Lo que Stripe retiene para la plataforma (application_fee_amount). 0 si no hay comisión.
            $table->decimal('comision_plataforma', 8, 2)->default(0);

            // Estado del pago. Nace en "pendiente" hasta que el webhook diga otra cosa.
            $table->string('estado')->default(EstadoPago::Pendiente->value);

            // Cuándo se creó el renglón. useCurrent() pone la fecha y hora del momento.
            $table->timestamp('creado_en')->useCurrent();

            // Un mismo PaymentIntent nunca se guarda dos veces (índice obligatorio del §3 del backend).
            // Es lo que impide procesar dos veces el mismo cobro.
            $table->unique('id_payment_intent', 'uq_pagos_id_payment_intent');

            // Índice para buscar rápido los pagos de un pedido (lo usa el historial, #69).
            $table->index('id_pedido', 'idx_pagos_pedido');
        });
    }

    // down() corre con "php artisan migrate:rollback": deshace lo de up().
    public function down(): void
    {
        // Borra la tabla si existe.
        Schema::dropIfExists('pagos');
    }
};