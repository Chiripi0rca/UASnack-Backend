<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Columnas de Stripe que todavía no existían (#108):
//   tiendas.id_cuenta_stripe y tiendas.cuenta_habilitada → la cuenta conectada de cada tienda (T-30)
//   usuarios.id_cliente_stripe → el cliente de Stripe de cada alumno, para guardar tarjetas (Anexo G)
return new class extends Migration
{
    public function up(): void
    {
        // Schema::table modifica una tabla que ya existe (no la crea).
        Schema::table('tiendas', function (Blueprint $table) {
            // Id de la cuenta conectada de la tienda en Stripe (acct_…). La llena la #109.
            // nullable: una tienda nueva todavía no tiene cuenta.
            $table->string('id_cuenta_stripe')->nullable();

            // Si Stripe ya le permite cobrar (charges_enabled). Nace en false:
            // mientras sea false, la tienda no puede recibir pedidos (§9.2). La actualiza la #146.
            $table->boolean('cuenta_habilitada')->default(false);

            // Dos tiendas no pueden tener la misma cuenta de Stripe.
            // Varias tiendas con null sí se permiten (todavía sin cuenta).
            $table->unique('id_cuenta_stripe', 'uq_tiendas_id_cuenta_stripe');
        });

        Schema::table('usuarios', function (Blueprint $table) {
            // Id del cliente del alumno en Stripe (cus_…). Lo crea la #65 la primera vez
            // que guarda una tarjeta; lo lee la #188 para listar sus tarjetas.
            $table->string('id_cliente_stripe')->nullable();

            // Dos alumnos no pueden compartir el mismo cliente de Stripe.
            $table->unique('id_cliente_stripe', 'uq_usuarios_id_cliente_stripe');
        });
    }

    // Deshace todo lo de up(), en orden inverso.
    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            // Primero se quita el índice único y luego la columna (SQLite lo exige en las pruebas).
            $table->dropUnique('uq_usuarios_id_cliente_stripe');
            $table->dropColumn('id_cliente_stripe');
        });

        Schema::table('tiendas', function (Blueprint $table) {
            $table->dropUnique('uq_tiendas_id_cuenta_stripe');
            $table->dropColumn(['id_cuenta_stripe', 'cuenta_habilitada']);
        });
    }
};