<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\EstadoTienda;
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tiendas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_facultad')->constrained('facultades');
            $table->string('nombre');
            $table->foreignId('id_dueno')->constrained('usuarios');
            $table->string('ubicacion')->nullable();
            $table->time('horario_apertura')->default('07:00');
            $table->time('horario_cierre')->default('19:00');
            $table->unsignedSmallInteger('duracion_franja_min')->default(10);
            $table->unsignedSmallInteger('cupo_bajo_demanda');
            $table->unsignedSmallInteger('umbral_espera_min');
            $table->string('activa')->default(EstadoTienda::Activa);
            $table->timestamp('creado_en')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tiendas');
    }
};
