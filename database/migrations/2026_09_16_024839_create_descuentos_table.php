<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\EstadoDescuento;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('descuentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_tienda')->constrained('tiendas');
            $table->foreignId('id_producto')->nullable()->constrained('productos');
            $table->foreignId('id_categoria')->nullable()->constrained('categorias');
            $table->unsignedTinyInteger('porcentaje');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->json('dias_semana'); // ej. [1,2,3,4,5]
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->string('activo')->default(EstadoDescuento::Activo);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('descuentos');
    }
};
