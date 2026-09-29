<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Migración que publicó Sanctum. Crea la tabla donde se guardan los tokens de login, (cada vez que AuthController::login llama a createToken(), se agrega una fila aquí).
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();

            // Crea dos columnas: tokenable_type y tokenable_id. Indican a quién pertenece
            // el token (aquí, un registro de la tabla usuarios).
            $table->morphs('tokenable');

            // Nombre del token. En el login se genera con el nombre 'cliente'.
            $table->text('name');

            // Versión hasheada del token (64 caracteres). El texto completo solo se ve una vez,
            // en la respuesta del login; en la base nunca queda guardado en claro.
            $table->string('token', 64)->unique();

            // Permisos del token. No se usan en UASnack (los permisos van por rol y policies).
            $table->text('abilities')->nullable();

             // Última vez que se usó el token para hacer una petición.
            $table->timestamp('last_used_at')->nullable();

            // Fecha de vencimiento. Con 'expiration' en config/sanctum.php (7 días), Sanctum
            // rechaza el token pasada la fecha. Tiene índice para revisar vencidos rápido.
            $table->timestamp('expires_at')->nullable()->index();

            // created_at y updated_at.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Elimina la tabla al revertir la migración (por ejemplo con migrate:rollback).
        Schema::dropIfExists('personal_access_tokens');
    }
};
