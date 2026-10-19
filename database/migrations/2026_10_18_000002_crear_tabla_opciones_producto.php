<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extras y quitables de cada producto (HU-04). Antes solo existían en Mongo y el seeder le ponía
// los mismos (queso, aguacate, sin cebolla, sin chile) a TODOS los productos, café incluido.
//
// Viven en PostgreSQL porque el precio de un extra entra al cobro: es dinero, y la fuente de verdad
// es SQL. catalogo_dinamico (Mongo) es solo la copia de lectura rápida que ve el alumno (#165).
//
// Cardinalidad: un producto tiene 0..N opciones; cada opción es de un solo producto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opciones_producto', function (Blueprint $table) {
            $table->id();
            // Si se borra el producto, sus opciones no tienen sentido sin él.
            $table->foreignId('id_producto')->constrained('productos')->cascadeOnDelete();
            $table->string('tipo');                          // extra | quitable
            $table->string('nombre');
            $table->decimal('precio', 8, 2)->default(0);     // solo los extras cuestan; un quitable va en 0
            $table->timestamps();

            $table->index(['id_producto', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opciones_producto');
    }
};