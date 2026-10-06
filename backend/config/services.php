<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Catálogo de veículos (Tabela FIPE via Parallelum). Sem token: 500 requisições/dia.
    'fipe' => [
        'base_url' => env('FIPE_BASE_URL', 'https://fipe.parallelum.com.br/api/v2'),
        'token' => env('FIPE_TOKEN'),
        'cache_days' => (int) env('FIPE_CACHE_DAYS', 7),
    ],

    // Dados do veículo pela placa (opcional). Provedores: "apibrasil" (100 consultas/dia grátis) ou "apiplacas".
    // Sem driver/token configurado, o painel apenas não oferece o preenchimento automático.
    'plate_lookup' => [
        'driver' => env('PLATE_LOOKUP_DRIVER', 'apibrasil'),
        'cache_days' => (int) env('PLATE_LOOKUP_CACHE_DAYS', 30),

        'apibrasil' => [
            'base_url' => env('APIBRASIL_BASE_URL', 'https://gateway.apibrasil.io/api/v2'),
            'bearer_token' => env('APIBRASIL_BEARER_TOKEN'),
            'device_token' => env('APIBRASIL_DEVICE_TOKEN'),
        ],

        'apiplacas' => [
            'base_url' => env('APIPLACAS_BASE_URL', 'https://wdapi2.com.br'),
            'token' => env('APIPLACAS_TOKEN'),
        ],
    ],

    // Consulta de endereço por CEP
    'viacep' => [
        'base_url' => env('VIACEP_BASE_URL', 'https://viacep.com.br/ws'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
