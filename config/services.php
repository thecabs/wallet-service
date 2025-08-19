<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */



    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
'keycloak' => [
    'token_url' => env('KEYCLOAK_TOKEN_URL'),
    'client_id' => env('KEYCLOAK_CLIENT_ID'),
    'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
],

 
  
    'user_management' => [
        'base_url' => env('USER_MANAGEMENT_URL'),
    ],
    'user_ceiling' => [
    'base_url' => env('USER_CEILING_BASE_URL', 'http://127.0.0.1:8001'),
],
];

