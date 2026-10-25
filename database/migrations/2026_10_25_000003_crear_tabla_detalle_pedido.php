<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Las líneas de cada pedido (T-45). Guarda el precio YA calculado por CatalogoService::calcularTotal (#49):
// si mañana cambia el precio o la promoción, el pedido conserva lo que se cobró (§7.6).
//
// Cardinalidad: un pedido tiene 1..N líneas; cada línea es de un solo pedido y un solo producto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_pedido', function (Blueprint $table) {
            $table->id();
            // Sin cascada: un pedido nunca se borra, es un registro de dinero.
            $table->foreignId('id_pedido')->constrained('pedidos');
            $table->foreignId('id_producto')->constrained('productos');
            $table->unsignedSmallInteger('cantidad');
            $table->decimal('precio_unitario', 8, 2);
            $table->decimal('descuento_aplicado', 8, 2)->default(0);
            $table->decimal('subtotal', 8, 2);
            $table->json('extras')->nullable();      // [{id, nombre, precio}] tal como los regresa calcularTotal
            $table->json('quitables')->nullable();   // [{id, nombre}]
            $table->string('notas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_pedido');
    }
};
