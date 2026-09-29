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
