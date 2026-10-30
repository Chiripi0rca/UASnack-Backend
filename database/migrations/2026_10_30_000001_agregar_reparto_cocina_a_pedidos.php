<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// En qué franjas se apartó la cocina de cada pedido. Un pedido se cocina en las franjas anteriores a
// su hora de recogida y puede repartirse en varias (CapacidadService::reservar), por ejemplo:
//   [{"franja_inicio": "2026-10-26 11:10:00", "minutos": 20}, {"franja_inicio": "2026-10-26 11:00:00", "minutos": 5}]
// Hace falta para devolver exactamente esos minutos si el pedido se cancela o se rechaza (liberar()).
// null en "voy saliendo" y en pedidos que no se cocinan: no apartan cocina.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->json('reparto_cocina')->nullable()->after('franja_fin');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('reparto_cocina');
        });
    }
};
