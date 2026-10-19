<?php

namespace App\Services;

use App\Documents\CatalogoDinamico;
use App\Exceptions\ErrorNegocio;
use App\Jobs\EspejarProductoEnMongo;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

use LogicException;

// ESQUELETO (Sprint 02). Cada método lo completa su tarea:
//   productosDe      → #45
//   calcularTotal    → #49
//   sincronizarMongo → #165 (hecho)
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
            //una sola consulta para toda la tienda
            return CatalogoDinamico::where('id_tienda', $tienda->id)
                ->get()
                ->keyBy('id_producto'); //permite buscar por id_producto
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
    // $lineas: [['id_producto' => 1, 'cantidad' => 2, 'extras' => [...]], ...]
    // El descuento por franja llega en la HU-20 (la tarea  262): déjale un punto marcado para aplicarlo.
    public function calcularTotal(array $lineas): float
    {
        throw new LogicException('Pendiente: #49');
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
