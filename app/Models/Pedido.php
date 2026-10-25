<?php

namespace App\Models;

use App\Enums\EstadoPedido;
use App\Models\Scopes\DeLaTiendaDelEncargado;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Si quien pide es un encargado, solo ve los pedidos de su tienda (#163).
#[ScopedBy([DeLaTiendaDelEncargado::class])]
class Pedido extends Model
{
    const CREATED_AT = 'creado_en';
    const UPDATED_AT = null;

    const RETIRO_FRANJA = 'franja';
    const RETIRO_VOY_SALIENDO = 'voy_saliendo';

    protected $table = 'pedidos';

    // estado, total, pin_retiro y codigo_pedido los pone PedidoService, nunca el cliente:
    // ningún controlador le pasa $request->all() a este modelo (§13.6).
    protected $fillable = [
        'codigo_pedido',
        'clave_idempotencia',
        'id_usuario',
        'id_tienda',
        'estado',
        'modo_retiro',
        'total',
        'metodo_pago',
        'minutos_cocina',
        'franja_inicio',
        'franja_fin',
        'pin_retiro',
        'tiempo_estimado_min',
        'motivo_rechazo',
        'aceptado_en',
        'listo_en',
        'llego_en',
        'entregado_en',
        'cancelado_en',
    ];

    // El alumno solo ve el PIN con el pedido en LISTO, y por su propio endpoint (§6.2).
    protected $hidden = [
        'pin_retiro',
    ];

    protected $casts = [
        'estado' => EstadoPedido::class,
        'total' => 'decimal:2',
        'franja_inicio' => 'datetime',
        'franja_fin' => 'datetime',
        'creado_en' => 'datetime',
        'aceptado_en' => 'datetime',
        'listo_en' => 'datetime',
        'llego_en' => 'datetime',
        'entregado_en' => 'datetime',
        'cancelado_en' => 'datetime',
    ];

    // Relaciones
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function tienda(): BelongsTo
    {
        return $this->belongsTo(Tienda::class, 'id_tienda');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(DetallePedido::class, 'id_pedido');
    }
}