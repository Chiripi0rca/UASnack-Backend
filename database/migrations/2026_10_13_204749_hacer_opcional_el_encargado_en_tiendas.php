<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Una tienda puede existir sin encargado ("Sin asignar"): el admin la da de alta
    // y después le asigna uno por correo (#104).
    public function up(): void
    {
        Schema::table('tiendas', function (Blueprint $table) {
            $table->foreignId('id_dueno')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tiendas', function (Blueprint $table) {
            $table->foreignId('id_dueno')->nullable(false)->change();
        });
    }
};
