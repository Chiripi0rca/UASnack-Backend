<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetallePedido extends Model
{
    protected $table = 'detalle_pedido';
    public $timestamps = false;
    protected $fillable = [
        'id_pedido',
        'id_producto',
        'cantidad',
        'precio_unitario',
        'descuento_aplicado',
        'subtotal',
        'extras',
        'quitables',
        'notas',
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'descuento_aplicado' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'extras' => 'array',
        'quitables' => 'array',
    ];

    // Relaciones
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'id_producto');
    }
}
