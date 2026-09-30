<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FacultadController;
use Illuminate\Support\Facades\Route;

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