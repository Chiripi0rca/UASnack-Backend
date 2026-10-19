<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Unique;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('apellido');
            $table->string('correo_institucional')->unique();
            $table->foreignId('id_facultad')->constrained('facultades'); //constrained lo que hace esta funcion es que le agreaga una condicion al metodo anterior, lo cual lo obliga a que existe ese dato si no existe no lo deja crear el registro
            $table->string('hash_password');
            $table->string('telefono');
            $table->integer('reputacion')->default(100);
            $table->decimal('saldo_cache', 10, 2)->default(0.00);
            $table->string('rol')->default('usuario'); // la reemplaza id_rol (migración de roles)
            $table->timestamp('correo_verificado_en')->nullable();
            $table->timestamp('terminos_aceptados_en')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamp('creado_en')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
