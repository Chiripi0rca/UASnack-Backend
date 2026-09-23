<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('capacidad_franjas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_tienda')->constrained('tiendas');
            $table->unsignedTinyInteger('dia_semana'); // 0-6
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->unsignedSmallInteger('minutos_cocina');
            $table->unique(['id_tienda', 'dia_semana', 'hora_inicio']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capacidad_franjas');
    }
};
