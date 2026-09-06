<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

   'allowed_origins' => [
    'http://localhost:3001',
    'https://chat-frontend.test',
],

'allowed_origins_patterns' => [
    '#^https://trans-chat-frontend(-[a-z0-9]+)?\.vercel\.app$#',
],
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
