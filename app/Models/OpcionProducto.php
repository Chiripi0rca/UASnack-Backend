<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Un extra (cuesta) o un quitable (no cuesta) de un producto (HU-04).
class OpcionProducto extends Model
{
    public const EXTRA = 'extra';

    public const QUITABLE = 'quitable';

    protected $table = 'opciones_producto';

    protected $fillable = [
        'tipo',
        'nombre',
        'precio',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'id_producto');
    }
}