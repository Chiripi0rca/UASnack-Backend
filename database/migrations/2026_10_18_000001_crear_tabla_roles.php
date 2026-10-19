<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// El rol deja de ser un texto (enum RolUsuario) dentro de usuarios y pasa a su propia tabla.
// Cardinalidad: un rol lo tienen muchos usuarios (1:N); cada usuario tiene exactamente un rol.
//
// Los tres roles se insertan aquí y no en un seeder: el sistema no funciona sin ellos,
// y así también existen en las pruebas (RefreshDatabase solo corre migraciones).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();   // la que viaja en el JSON y en las rutas: rol:administrador
            $table->string('nombre');            // para mostrar en el panel
        });

        DB::table('roles')->insert([
            ['clave' => 'usuario', 'nombre' => 'Usuario'],
            ['clave' => 'encargado_tienda', 'nombre' => 'Encargado de tienda'],
            ['clave' => 'administrador', 'nombre' => 'Administrador'],
        ]);

        Schema::table('usuarios', function (Blueprint $table) {
            $table->foreignId('id_rol')->nullable()->after('telefono')->constrained('roles');
        });

        // Cada usuario existente conserva el rol que tenía como texto.
        foreach (DB::table('roles')->get() as $rol) {
            DB::table('usuarios')->where('rol', $rol->clave)->update(['id_rol' => $rol->id]);
        }

        // Cualquier valor raro que hubiera quedado se vuelve "usuario", el rol con menos permisos.
        $idUsuario = DB::table('roles')->where('clave', 'usuario')->value('id');
        DB::table('usuarios')->whereNull('id_rol')->update(['id_rol' => $idUsuario]);

        Schema::table('usuarios', function (Blueprint $table) {
            $table->foreignId('id_rol')->nullable(false)->change();
            $table->dropColumn('rol');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('rol')->default('usuario');
        });

        foreach (DB::table('roles')->get() as $rol) {
            DB::table('usuarios')->where('id_rol', $rol->id)->update(['rol' => $rol->clave]);
        }

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_rol');
        });

        Schema::dropIfExists('roles');
    }
};
