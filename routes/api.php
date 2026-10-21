<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\FacultadController;
use App\Http\Controllers\Api\V1\TiendaController;
use App\Http\Controllers\Api\V1\TiendaAdminController;
use App\Http\Controllers\Api\V1\ProductoPanelController;
use App\Http\Resources\UsuarioResource;
use Illuminate\Support\Facades\Route;
// Las rutas van agrupadas por bloque. Cada tarea agrega sus rutas
// SOLO dentro de su bloque, así dos ramas que tocan este archivo no chocan en git.


// Público y autenticación

Route::get('/facultades', [FacultadController::class, 'obtenerFacultades']);

// Registro: máximo 5 peticiones por minuto (#232). Cada registro manda un correo,
// así que sin límite cualquiera podría usarlo para llenar buzones o el SMTP.
Route::post('/auth/registro', [AuthController::class, 'registro'])
    ->middleware('throttle:5,1');

// Login: máximo 5 peticiones por minuto.
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

// Logout: requiere un token válido de Sanctum.
Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum');

// Verificar correo.
Route::post('/auth/verificar', [AuthController::class, 'verificar'])
    ->middleware('throttle:10,1');

// Reenviar código de verificación.
Route::post('/auth/reenviar-codigo', [AuthController::class, 'reenviarCodigo'])
    ->middleware('throttle:3,1');


// Alumno: catálogo. Requiere sesión.

Route::middleware('auth:sanctum')->group(function () {
    // #46: GET /tiendas, GET /tiendas/{id}/productos
    // y GET /productos/{id}

    Route::get('/tiendas', [TiendaController::class, 'obtenerTiendas']);

    Route::get(
        '/tiendas/{id}/productos',
        [CatalogoController::class, 'productos']
    )->whereNumber('id');

    Route::get(
        '/productos/{id}',
        [CatalogoController::class, 'detalleProducto']
    )->whereNumber('id');
});


// Alumno: pedidos. Requiere sesión y correo verificado.

Route::middleware(['auth:sanctum', 'correo.verificado'])->group(function () {
    // Aquí va POST /pedidos cuando exista PedidoController (tarea 49).
    // Route::post('/pedidos', [PedidoController::class, 'crear'])
    //     ->middleware('throttle:10,1');
});


// Panel de tienda (encargado). Prefijo /tienda.

Route::prefix('tienda')
    ->middleware(['auth:sanctum', 'rol:encargado_tienda', 'tenant'])
    ->group(function () {
       Route::get('/productos', [ProductoPanelController::class, 'obtenerProductos']);
        Route::post('/productos', [ProductoPanelController::class, 'crearProducto']);
        Route::patch('/productos/{id}', [ProductoPanelController::class, 'actualizarProducto'])
            ->whereNumber('id');
        Route::patch('/productos/{id}/stock', [ProductoPanelController::class, 'actualizarStock'])
            ->whereNumber('id');
    });


// Panel de administración. Prefijo /admin.
// "rol" y "tenant" todavía son esqueletos (los completa la tarea 162): ya puedes agregar tus rutas,
// pero mientras la tarea 162 no esté en dev no se revisa el rol. Nada de esto llega a main sin la tarea 162.


Route::prefix('admin')
    ->middleware(['auth:sanctum', 'rol:administrador', 'tenant'])
    ->group(function () {
        // HU-31: Asignar o cambiar el encargado de una tienda.
        Route::patch(
            '/tiendas/{id}/encargado',
            [TiendaAdminController::class, 'asignarEncargado']
        )->whereNumber('id');

        // #102: GET/POST/PATCH /admin/tiendas
        Route::get(
            '/tiendas',
            [TiendaAdminController::class, 'obtenerTiendas']
        );

        Route::post(
            '/tiendas',
            [TiendaAdminController::class, 'crearTienda']
        );

        Route::patch(
            '/tiendas/{id}',
            [TiendaAdminController::class, 'actualizarTienda']
        )->whereNumber('id');

        // #104: encargados de la facultad, y búsqueda por correo antes de asignar
        Route::get(
            '/encargados',
            [TiendaAdminController::class, 'obtenerEncargados']
        );

        Route::get(
            '/usuarios/buscar',
            [TiendaAdminController::class, 'buscarUsuario']
        );
    });

// Consulta la sesión actual.

Route::middleware('auth:sanctum')->get('/auth/yo', function (
    \Illuminate\Http\Request $request
) {
    return response()->json([
        'usuario' => new UsuarioResource($request->user()),
    ]);
});
