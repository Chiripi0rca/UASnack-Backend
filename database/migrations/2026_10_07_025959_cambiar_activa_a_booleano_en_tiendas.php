<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // "activa" nació como texto ('activa' / 'inactiva') y el documento la define booleana.
    // Se hace en pasos (columna nueva, copiar, borrar la vieja, renombrar) porque PostgreSQL
    // no convierte texto a booleano solo, y así también corre en el SQLite de las pruebas.
    public function up(): void
    {
        Schema::table('tiendas', function (Blueprint $table) {
            $table->boolean('activa_nueva')->default(true);
        });

        DB::table('tiendas')->where('activa', 'inactiva')->update(['activa_nueva' => false]);

        Schema::table('tiendas', function (Blueprint $table) {
            $table->dropColumn('activa');
        });

        Schema::table('tiendas', function (Blueprint $table) {
            $table->renameColumn('activa_nueva', 'activa');
        });
    }

    public function down(): void
    {
        Schema::table('tiendas', function (Blueprint $table) {
            $table->string('activa_texto')->default('activa');
        });

        DB::table('tiendas')->where('activa', false)->update(['activa_texto' => 'inactiva']);

        Schema::table('tiendas', function (Blueprint $table) {
            $table->dropColumn('activa');
        });

        Schema::table('tiendas', function (Blueprint $table) {
            $table->renameColumn('activa_texto', 'activa');
        });
    }
};
