<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\Tienda;
use Illuminate\Support\Collection;
use LogicException;

// ESQUELETO (Sprint 02). Cada método lo completa su tarea:
//   productosDe      → #45
//   calcularTotal    → #49
//   sincronizarMongo → #165
// Si una firma no te sirve, acuérdalo con los demás antes de cambiarla.
class CatalogoService
{
    // Llave de Redis donde se guarda el catálogo de una tienda.
    // la tarea 45 guarda con ella y la tarea 165 la borra: por eso es una sola constante para los dos.
    public const CLAVE_CACHE = 'catalogo:tienda:%d';

    public static function claveCache(int $idTienda): string
    {
        return sprintf(self::CLAVE_CACHE, $idTienda);
    }

    // la tarea  45: productos de la tienda con existencias, extras y quitables, guardados en caché.
    // Los agotados se incluyen marcados, nunca se filtran.
    public function productosDe(Tienda $tienda, ?int $idCategoria = null): Collection
    {
        throw new LogicException('Pendiente: #45');
    }

    // la tarea 49: total calculado en el servidor: (precio + extras) × cantidad. Nunca usa precios del cliente.
    // $lineas: [['id_producto' => 1, 'cantidad' => 2, 'extras' => [...]], ...]
    // El descuento por franja llega en la HU-20 (la tarea  262): déjale un punto marcado para aplicarlo.
    public function calcularTotal(array $lineas): float
    {
        throw new LogicException('Pendiente: #49');
    }

    // la tarea 165: copia el producto a catalogo_dinamico (Mongo) y borra la caché de su tienda.
    public function sincronizarMongo(Producto $producto): void
    {
        throw new LogicException('Pendiente: #165');
    }
}
