<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\EstadoProducto;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_tienda')->constrained('tiendas');
            $table->foreignId('id_categoria')->constrained('categorias');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->decimal('precio', 8, 2);
            $table->unsignedInteger('stock_hoy')->default(0);
            $table->unsignedSmallInteger('tiempo_prep_min');
            $table->unsignedSmallInteger('tiempo_prep_max');
            $table->string('foto_url')->nullable();
            $table->string('estado_producto')->default(EstadoProducto::Disponible);
            $table->timestamps();

            $table->index(['id_tienda', 'id_categoria']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
