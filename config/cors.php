<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // Rutas a las que se aplica CORS: todas las de la API (api/*).
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // Métodos HTTP permitidos desde otro origen (GET, POST, etc.). '*' = todos.
    'allowed_methods' => ['*'],

    // Desde qué dominios se puede llamar a la API. '*' = cualquiera.
    // Sirve en desarrollo (la web corre en localhost:5173 y la API en localhost:8000),
    // pero en producción conviene limitarlo al dominio real de la PWA y del panel.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    // Headers que el cliente puede enviar (Authorization, Content-Type, etc.). '*' = todos.
    'allowed_headers' => ['*'],

    // Headers de la RESPUESTA que el navegador deja leer al JavaScript de la web.
    // Sin esto, el navegador los oculta aunque el servidor los mande.
    //   X-RateLimit-Remaining -> intentos que le quedan al usuario antes del bloqueo
    //   X-RateLimit-Limit     -> máximo de intentos permitidos (5 en el login)
    //   Retry-After           -> segundos de espera cuando llega el error 429
    'exposed_headers' => ['X-RateLimit-Remaining', 'X-RateLimit-Limit', 'Retry-After'],

    // Segundos que el navegador guarda en caché la verificación previa de CORS. 0 = sin caché.
    'max_age' => 0,

    // Si se permiten cookies entre orígenes. No se usan: la autenticación va por token Bearer.
    'supports_credentials' => false,

];
