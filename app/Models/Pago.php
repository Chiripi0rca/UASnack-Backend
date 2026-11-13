<?php

namespace App\Models;

// El enum de estados, para que "estado" salga como EstadoPago y no como texto suelto.
use App\Enums\EstadoPago;
// Clase base de los modelos de Eloquent.
use Illuminate\Database\Eloquent\Model;
// Tipo de la relación "pertenece a".
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Un intento de cobro con Stripe (tabla pagos). Lo crea PagoService::crearIntento (#61),
// lo marca pagado o fallido el webhook (#62) y lo lee el historial (#69).
class Pago extends Model
{
    // Le dice a Eloquent que la fecha de creación se llama creado_en…
    const CREATED_AT = 'creado_en';
    // …y que esta tabla no tiene columna de "actualizado".
    const UPDATED_AT = null;

    // Nombre de la tabla en la base.
    protected $table = 'pagos';

    // Campos que se pueden llenar con Pago::create([...]).
    // Los llena siempre PagoService, nunca la app: ningún controlador le pasa $request->all() (§13.6).
    protected $fillable = [
        'id_pedido',
        'id_payment_intent',
        'id_charge',
        'monto',
        'comision_plataforma',
        'estado',
    ];

    // Convierte los valores de la base al tipo correcto al leerlos.
    protected $casts = [
        'estado' => EstadoPago::class,         // texto "pagado" → EstadoPago::Pagado
        'monto' => 'decimal:2',                // siempre con 2 decimales: "85.00"
        'comision_plataforma' => 'decimal:2',
        'creado_en' => 'datetime',             // texto → fecha manejable (Carbon)
    ];

    // Relación: este pago pertenece a un pedido. Uso: $pago->pedido
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido');
    }
}