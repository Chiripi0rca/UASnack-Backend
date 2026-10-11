<?php
use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FacultadController;
use App\Http\Controllers\Api\V1\TiendaController;
use Illuminate\Support\Facades\Route;

// Las rutas van agrupadas por bloque. Cada tarea agrega sus rutas SOLO dentro de su bloque,
// así dos ramas que tocan este archivo no chocan en git.


// Público y autenticación


Route::get('/facultades', [FacultadController::class, 'obtenerFacultades']);
Route::post('/auth/registro', [AuthController::class, 'registro']);

// Login: recibe correo y contraseña y devuelve un token de Sanctum.
// throttle:5,1 = máximo 5 peticiones por minuto. Al sexto intento responde 429
// (Too Many Attempts) con el header Retry-After, para frenar ataques de fuerza bruta.
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

// Logout: solo entra quien manda un token válido (Authorization: Bearer <token>).
// Sin token o con uno ya revocado, auth:sanctum responde 401 antes de llegar al controlador.
Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum');

// Verificar: recibe correo y código de 6 dígitos. Si coincide, marca el correo como verificado.
// throttle:10,1 = máximo 10 peticiones por minuto, para frenar a quien intente adivinar códigos.
Route::post('/auth/verificar', [AuthController::class, 'verificar'])
    ->middleware('throttle:10,1');

// Reenviar código: recibe el correo y genera un código nuevo, invalidando los anteriores.
// throttle:3,1 = máximo 3 por minuto, para que nadie use la API para llenar de spam un correo.
Route::post('/auth/reenviar-codigo', [AuthController::class, 'reenviarCodigo'])
    ->middleware('throttle:3,1');


// Alumno: catálogo (HU-03). Con sesión.


Route::middleware('auth:sanctum')->group(function () {
    // #46: GET /tiendas, GET /tiendas/{id}/productos y GET /productos/{id}
    Route::get('/tiendas', [TiendaController::class, 'obtenerTiendas']);
    Route::get('/tiendas/{id}/productos', [CatalogoController::class, 'productos']);
    Route::get('/productos/{id}', [CatalogoController::class, 'detalleProducto']);
});


// Alumno: pedidos. Con sesión y correo verificado.


// Rutas de pedidos: solo usuarios con sesión (auth:sanctum) Y con el correo verificado (correo.verificado).
// Si el correo no está verificado responde 403 CORREO_NO_VERIFICADO antes de llegar al controlador.
// El orden importa: primero auth:sanctum (¿quién eres?) y después correo.verificado (¿ya verificaste?).
Route::middleware(['auth:sanctum', 'correo.verificado'])->group(function () {
    // Aquí va POST /pedidos cuando exista PedidoController (tarea 49), por ejemplo:
    // Route::post('/pedidos', [PedidoController::class, 'crear'])->middleware('throttle:10,1');
});

// Panel de tienda (encargado). Prefijo /tienda.
// "rol" y "tenant" todavía son esqueletos (los completa la tarea 162): ya puedes agregar tus rutas,
// pero mientras la tarea 162 no esté en dev no se revisa el rol. Nada de esto llega a main sin la tarea 162.


Route::prefix('tienda')
    ->middleware(['auth:sanctum', 'rol:encargado_tienda', 'tenant'])
    ->group(function () {
        // #99: GET/POST/PATCH /tienda/productos y PATCH /tienda/productos/{id}/stock
    });


// Panel de administración. Prefijo /admin.
// "rol" y "tenant" todavía son esqueletos (los completa la tarea 162): ya puedes agregar tus rutas,
// pero mientras la tarea 162 no esté en dev no se revisa el rol. Nada de esto llega a main sin la tarea 162.


Route::prefix('admin')
    ->middleware(['auth:sanctum', 'rol:administrador', 'tenant'])
    ->group(function () {
        // #102: GET/POST/PATCH /admin/tiendas
        // #104: GET /admin/encargados
    });