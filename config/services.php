<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services
    | such as Mailgun, Postmark, AWS and more.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | NVIDIA AI Services
    |--------------------------------------------------------------------------
    */
    'nvidia' => [
        'base_url' => env('NVIDIA_API_URL', 'https://integrate.api.nvidia.com'),
        'api_key' => env('NVIDIA_API_KEY'),
        'llm_model' => env('NVIDIA_LLM_MODEL', 'meta/llama-3.1-405b-instruct'),
        'stt_model' => env('NVIDIA_STT_MODEL', 'nvidia/parakeet-rnnt-1.1b'),
        'vision_model' => env('NVIDIA_VISION_MODEL', 'microsoft/kosmos-2'),
        'timeout' => env('NVIDIA_TIMEOUT', 15),
        'max_retries' => env('NVIDIA_MAX_RETRIES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business API (Twilio)
    |--------------------------------------------------------------------------
    */
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_number' => env('TWILIO_WHATSAPP_NUMBER'),
        'from' => env('TWILIO_WHATSAPP_NUMBER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta WhatsApp Cloud API Alternative
    |--------------------------------------------------------------------------
    */
    'meta' => [
        'api_version' => env('META_API_VERSION', 'v18.0'),
        'access_token' => env('META_ACCESS_TOKEN'),
        'phone_number_id' => env('META_PHONE_NUMBER_ID'),
        'business_account_id' => env('META_BUSINESS_ACCOUNT_ID'),
        'verify_token' => env('META_VERIFY_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Product Catalog Configuration
    |--------------------------------------------------------------------------
    */
    'products' => [
        'catalog_file' => storage_path('app/products.json'),
        'default_currency' => 'MAD',
        'default_unit' => 'كيلو',
    ],
];
