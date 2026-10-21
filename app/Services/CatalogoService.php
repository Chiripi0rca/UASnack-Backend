<?php

namespace App\Services;

use App\Documents\CatalogoDinamico;
use App\Exceptions\ErrorNegocio;
use App\Jobs\EspejarProductoEnMongo;
use App\Models\OpcionProducto;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

// Sprint 02. Cada método lo completó su tarea:
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
        // Busca el catálogo en Redis. Si existe, lo devuelve tal cual.
        // Si no existe, ejecuta armarCatalogo(), lo guarda y lo devuelve.
        $catalogo = Cache::remember(
            self::claveCache($tienda->id),   // llave: "catalogo:tienda:{id}" 
            now()->addMinutes(10),           // tiempo de vida: a los 10 min se renueva sola
            fn () => $this->armarCatalogo($tienda) // solo corre si la llave no existe
        );

        // Lo guardado en Redis es el catalogo completo de la tienda
        //aqui se convierte en coleccion para poder filtrarlo
        return collect($catalogo)
            //si mandaron categoria, se queda solo con los productos de esa categoria
            // si no, no filtra nada
            ->when($idCategoria, fn ($c) => $c->where('id_categoria', $idCategoria))
            //renumera los indies para que el json salga como lista
            ->values();
    }

    //arma el catalogo completo desde las bases de datos
    //es privado: solo se usa dentro de esta clase, y solo cuando redis no tiene la copia
    //Devuelve arreglos simples (no modelos) por que asi se guardan bien en redis
    private function armarCatalogo(Tienda $tienda): array
    {
        //Trae todos los productos de la tienda, incluidos los agotados
        //withoutGlobalScopes: no depender del filtro del encargado
        //por que aqui consulta un alumno
        $productos = Producto::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->orderBy('nombre')
            ->get();

        
        $personalizaciones = $this->personalizacionesDe($tienda); //se traen las personalizaciones

        //convierte cada modelo en un arreglo simple (Redis guarda mejor arreglos)
        return $productos->map(function (Producto $p) use ($personalizaciones){
            //si el modelo castea estado_producto a un enum, se saca su valor en texto
            $estado = $p->estado_producto instanceof \BackedEnum
                ? $p->estado_producto->value
                : $p->estado_producto;

            return [
                'id' => $p->id,
                'id_tienda' => $p->id_tienda,
                'id_categoria' => $p->id_categoria,
                'nombre' => $p->nombre,
                'descripcion' => $p->descripcion,
                'precio' => (float) $p->precio,
                'stock_hoy' => $p->stock_hoy,
                'tiempo_prep_min' => $p->tiempo_prep_min,
                'tiempo_prep_max' => $p->tiempo_prep_max,
                'foto_url' => $p->foto_url,

                //Agotado si asi esta marcada o si no queda stock
                //nunca se filtra: el alumno lo ve atenuado
                'agotado' => $estado === 'agotado' || $p->stock_hoy <= 0,

                // Extras y quitables de Mongo. Un producto sin documento sale con
                // lista vacía: eso es normal, no un fallo.
                'personalizaciones' => $personalizaciones->get($p->id)?->personalizaciones ?? [],

            ];
        })->all();
    }

    // Lee de Mongo los documentos de la tienda, indexados por id_producto.
    // Si Mongo no responde NO se devuelve un catálogo sin extras: se lanza error.
    private function personalizacionesDe(Tienda $tienda): Collection
    {
        try {
            return $this->documentosDeMongo($tienda);
        } catch (\Throwable $e) {
              // Deja el error real en storage/logs/laravel.log para investigarlo
            report($e);

            //se lanza el error si no carga el catalogo
            throw new ErrorNegocio(
                'CATALOGO_NO_DISPONIBLE',
                'No pudimos cargar el catalogo, intentelo de nuevo',
                503
            );
        }

    }

    // La consulta a Mongo, sola. Es protected para que las pruebas la simulen (#230):
    // la suite corre sin Mongo, y así se prueba también el 503 cuando Mongo no responde.
    protected function documentosDeMongo(Tienda $tienda): Collection
    {
        //una sola consulta para toda la tienda
        return CatalogoDinamico::where('id_tienda', $tienda->id)
            ->get()
            ->keyBy('id_producto'); //permite buscar por id_producto
    }

    public function producto(int $id): array
    {
        //se trae el producto mediante el id
        $producto = Producto::withoutGlobalScopes()->find($id);

        if(! $producto){
            $this->noEncontrado();
        }

        //veririca que su tienda este activa y pide el catalogo
        //se toma solo este este producto para que el detalle siempre coincida con la lista
        $detalle = $this->productosDe($this->tiendaActiva($producto->id_tienda))
            ->firstWhere('id', $producto->id);

        if(! $detalle){
            $this->noEncontrado();
        }

        return $detalle;
    }

     // Devuelve la tienda si existe y está activa; si no, lanza 404.
    // Inexistente y de baja dan el mismo error, para no revelar cuál es cuál.
    public function tiendaActiva(int $id): tienda
    {
        //busca por id, solo entre las activas, si no hay se queda en null
        $tienda = Tienda::where('activa', true)->find($id);

        if(! $tienda){
            $this->noEncontrado();
        }

        return $tienda;
    }


    // la tarea 49: total calculado en el servidor: (precio + extras) × cantidad. Nunca usa precios del cliente.
    // $lineas: [['id_producto' => 1, 'cantidad' => 2, 'extras' => ['3', '4'], 'quitables' => ['7']], ...]
    //   extras y quitables son los id de opciones_producto (los mismos que llegan en personalizaciones).
    //   Cualquier precio o total que mande el cliente se ignora.
    //
    // Devuelve el total y el desglose por línea, que PedidoService guarda tal cual en detalle_pedido:
    //   ['total' => 90.0, 'lineas' => [['id_producto', 'id_tienda', 'nombre', 'cantidad', 'extras',
    //     'quitables', 'precio_unitario', 'descuento_aplicado', 'subtotal'], ...]]
    //
    // Los precios salen de PostgreSQL (productos y opciones_producto), nunca de Mongo ni de la caché:
    // es dinero. Se suma en centavos (enteros) para que 0.1 + 0.2 no dé 0.30000000000000004.
    public function calcularTotal(array $lineas, ?Carbon $franja = null): array
    {
        if ($lineas === []) {
            throw new ErrorNegocio('CARRITO_VACIO', 'Agrega al menos un producto.', 422);
        }

        $productos = Producto::withoutGlobalScopes()
            ->with('opciones')
            ->whereIn('id', array_map(fn (array $l) => (int) ($l['id_producto'] ?? 0), $lineas))
            ->get()
            ->keyBy('id');

        $idTienda = null;
        $totalCentavos = 0;
        $desglose = [];

        foreach ($lineas as $linea) {
            $producto = $productos->get((int) ($linea['id_producto'] ?? 0));

            if (! $producto) {
                $this->noEncontrado();
            }

            // Un pedido es de una sola tienda: el cobro va a la cuenta de esa tienda.
            $idTienda ??= $producto->id_tienda;
            if ($producto->id_tienda !== $idTienda) {
                throw new ErrorNegocio('VARIAS_TIENDAS', 'Un pedido solo puede tener productos de una tienda.', 422);
            }

            $cantidad = (int) ($linea['cantidad'] ?? 0);
            if ($cantidad < 1) {
                throw new ErrorNegocio('CANTIDAD_INVALIDA', 'La cantidad debe ser de al menos 1.', 422);
            }

            $extras = $this->opcionesElegidas($producto, $linea['extras'] ?? [], OpcionProducto::EXTRA);
            $quitables = $this->opcionesElegidas($producto, $linea['quitables'] ?? [], OpcionProducto::QUITABLE);

            $unitarioCentavos = $this->aCentavos($producto->precio)
                + $extras->sum(fn (OpcionProducto $o) => $this->aCentavos($o->precio));

            // HU-20 (#262): aquí se aplica el descuento vigente de $franja para este producto
            // o su categoría. Mientras no exista, el descuento es 0.
            $descuentoCentavos = 0;

            $subtotalCentavos = $unitarioCentavos * $cantidad - $descuentoCentavos;
            $totalCentavos += $subtotalCentavos;

            $desglose[] = [
                'id_producto' => $producto->id,
                'id_tienda' => $producto->id_tienda,
                'nombre' => $producto->nombre,
                'cantidad' => $cantidad,
                'extras' => $extras->map(fn (OpcionProducto $o) => [
                    'id' => (string) $o->id, 'nombre' => $o->nombre, 'precio' => (float) $o->precio,
                ])->values()->all(),
                'quitables' => $quitables->map(fn (OpcionProducto $o) => [
                    'id' => (string) $o->id, 'nombre' => $o->nombre,
                ])->values()->all(),
                'precio_unitario' => $unitarioCentavos / 100,
                'descuento_aplicado' => $descuentoCentavos / 100,
                'subtotal' => $subtotalCentavos / 100,
            ];
        }

        return ['total' => $totalCentavos / 100, 'lineas' => $desglose];
    }

    // Las opciones de ese tipo que eligió el cliente, buscadas entre las del propio producto.
    // Un id que no es de este producto (o es de otro tipo) se rechaza: así no se cuela
    // un extra de otro producto ni se cobra un quitable como extra.
    private function opcionesElegidas(Producto $producto, array $ids, string $tipo): Collection
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));

        $elegidas = $producto->opciones
            ->where('tipo', $tipo)
            ->filter(fn (OpcionProducto $o) => in_array((string) $o->id, $ids, true));

        if ($elegidas->count() !== count($ids)) {
            throw new ErrorNegocio(
                'OPCION_INVALIDA',
                "Una de las opciones elegidas ya no está disponible para {$producto->nombre}.",
                422
            );
        }

        return $elegidas;
    }

    private function aCentavos(string|float|int $importe): int
    {
        return (int) round(((float) $importe) * 100);
    }

    // la tarea 165: copia el producto a catalogo_dinamico (Mongo) y borra la caché de su tienda.
    // Se llama después de crear o editar un producto, o de cambiarle el stock.
    public function sincronizarMongo(Producto $producto): void
    {
        // La caché se borra ya: el stock y el precio salen de SQL y deben verse al momento.
        Cache::forget(self::claveCache($producto->id_tienda));

        // La copia a Mongo va en cola y solo después del commit: si la transacción del
        // producto se revierte, no queda en Mongo algo que no existe en SQL.
        EspejarProductoEnMongo::dispatch($producto->id)->afterCommit();
    }

    // Un solo lugar para el 404. "never" avisa a PHP que siempre lanza excepción.
    private function noEncontrado(): never
    {
        throw new ErrorNegocio('NO_ENCONTRADO', 'No encontramos lo que buscas.', 404);
    }
}
