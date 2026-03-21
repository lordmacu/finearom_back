<?php

return [
    'username_facturacion' => env('MAIL_USERNAME_FACTURACION'),

    'dsn' => env('MAILER_DSN'),
    'dsn_marlon' => env('MAILER_DSN_MARLON'),
    'dsn_cartera' => env('MAILER_DSN_CARTERA'),

    'ai_server_url' => env('AI_SERVER_URL', 'http://localhost:54321'),
    'ai_server_key' => env('AI_SERVER_KEY', 'finearom-ai-2025'),

    'deepseek_api_key' => env('DEEPSEEK_API_KEY', ''),
];
