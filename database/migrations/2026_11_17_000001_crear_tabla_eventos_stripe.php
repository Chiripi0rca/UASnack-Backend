<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabla eventos_stripe (#77): los avisos de Stripe que ya se procesaron.
// Stripe puede mandar el mismo aviso más de una vez; con el id del evento como llave, la segunda se ignora.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_stripe', function (Blueprint $table) {
            // id del evento en Stripe (evt_…). Al ser llave primaria, no se puede guardar dos veces.
            $table->string('id_evento')->primary();

            // tipo del evento (account.updated, payment_intent.succeeded…), solo para revisar la bitácora
            $table->string('tipo');

            $table->timestamp('recibido_en')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_stripe');
    }
};
