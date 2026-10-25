<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Minutos de cocina ya apartados en cada franja de un día concreto (#105).
// capacidad_franjas dice cuánto CABE ("los lunes a las 11:00 caben 24 min");
// esta tabla dice cuánto ya se APARTÓ ("el lunes 26 a las 11:00 van 18 min").
//
// Cardinalidad: una tienda tiene 0..N renglones, uno por franja que ya tuvo al menos una reserva.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocupacion_franjas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_tienda')->constrained('tiendas');
            $table->dateTime('franja_inicio');                              // fecha y hora en que empieza la franja
            $table->unsignedSmallInteger('minutos_ocupados')->default(0);

            $table->unique(['id_tienda', 'franja_inicio'], 'uq_ocupacion_franjas_tienda_franja');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocupacion_franjas');
    }
};
