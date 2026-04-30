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

    'coordinadora_client_id'     => env('COORDINADORA_CLIENT_ID', ''),
    'coordinadora_client_secret' => env('COORDINADORA_CLIENT_SECRET', ''),
    'coordinadora_base_url'      => env('COORDINADORA_BASE_URL', 'https://apiv2.coordinadora.com'),
    'coordinadora_auth_url'      => env('COORDINADORA_AUTH_URL', 'https://api.coordinadora.tech'),

    'siigo_proxy_url'      => env('SIIGO_PROXY_URL', ''),
    'siigo_proxy_username' => env('SIIGO_PROXY_USERNAME', ''),
    'siigo_proxy_password' => env('SIIGO_PROXY_PASSWORD', ''),
];
