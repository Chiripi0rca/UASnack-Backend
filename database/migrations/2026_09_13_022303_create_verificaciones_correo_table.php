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
        Schema::create('verificaciones_correo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_usuario')->constrained('usuarios');
            $table->string('tipo')->default('verificacion');
            $table->string('codigo_hash');
            $table->timestamp('expira_en');
            $table->integer('intentos')->default(0);
            $table->timestamp('consumido_en')->nullable();
            $table->timestamp('creado_en')->useCurrent(); // cuando se usa useCurrent() se crea solo un campo de tipo timestamp y se le asigna la fecha y hora actual al momento de crear el registro
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verificaciones_correo');
    }
};
