<?php

return [
    'username_facturacion' => env('MAIL_USERNAME_FACTURACION'),

    'dsn' => env('MAILER_DSN'),
    'dsn_marlon' => env('MAILER_DSN_MARLON'),
    'dsn_cartera' => env('MAILER_DSN_CARTERA'),

    'ai_server_url' => env('AI_SERVER_URL', 'http://localhost:54321'),
    'ai_server_key' => env('AI_SERVER_KEY', 'finearom-ai-2025'),

    'deepseek_api_key' => env('DEEPSEEK_API_KEY', ''),

    'dhl_username' => env('DHL_USERNAME', ''),
    'dhl_password' => env('DHL_PASSWORD', ''),
    'dhl_base_url' => env('DHL_BASE_URL', 'https://express.api.dhl.com/mydhlapi'),

    // Códigos typeCode de DHL que significan novedad (no entregado, no devuelto).
    // Arranca vacío a propósito: se van agregando con códigos vistos en producción.
    // trim por elemento: en el .env de prod la lista se separa "HP, UD" con espacio.
    'dhl_exception_codes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DHL_EXCEPTION_CODES', ''))
    ))),

    'coordinadora_client_id'     => env('COORDINADORA_CLIENT_ID', ''),
    'coordinadora_client_secret' => env('COORDINADORA_CLIENT_SECRET', ''),
    'coordinadora_auth_url'      => env('COORDINADORA_AUTH_URL', 'https://api.coordinadora.tech'),
    'coordinadora_guias_url'     => env('COORDINADORA_GUIAS_URL', 'https://guias-service.coordinadora.com'),

    'siigo_proxy_url'      => env('SIIGO_PROXY_URL', ''),
    'siigo_proxy_username' => env('SIIGO_PROXY_USERNAME', ''),
    'siigo_proxy_password' => env('SIIGO_PROXY_PASSWORD', ''),

    'vanna_url'        => env('VANNA_URL', 'http://127.0.0.1:8000'),
    'vanna_enabled'    => env('VANNA_ENABLED', false),
    'vanna_timeout_ms' => (int) env('VANNA_TIMEOUT_MS', 600),
    'vanna_k'          => (int) env('VANNA_K', 8),
];
