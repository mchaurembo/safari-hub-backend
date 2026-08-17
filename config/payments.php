<?php

use App\Services\Payments\Gateways\HttpPaymentGateway;
use App\Services\Payments\Gateways\SelcomPaymentGateway;
use App\Services\Payments\Gateways\StubPaymentGateway;

return [

    'default_currency' => env('PAYMENT_DEFAULT_CURRENCY', 'TZS'),

    'default_driver' => env('PAYMENT_PROVIDER', 'stub'),

    'reference_prefix' => env('PAYMENT_REFERENCE_PREFIX', 'SH-PAY'),

    'idempotency_ttl_hours' => (int) env('PAYMENT_IDEMPOTENCY_TTL_HOURS', 24),

    'drivers' => [
        'stub' => StubPaymentGateway::class,
        'http' => HttpPaymentGateway::class,
        'selcom' => SelcomPaymentGateway::class,
    ],

    /*
     | Optional explicit method → driver map. Empty = use gateway.supported_methods + priority.
     */
    'method_gateway_map' => array_filter([
        'VISA' => env('PAYMENT_GATEWAY_VISA'),
        'MASTERCARD' => env('PAYMENT_GATEWAY_MASTERCARD'),
        'MPESA' => env('PAYMENT_GATEWAY_MPESA'),
        'MIXX_BY_YAS' => env('PAYMENT_GATEWAY_MIXX'),
        'AIRTEL_MONEY' => env('PAYMENT_GATEWAY_AIRTEL'),
        'HALOPESA' => env('PAYMENT_GATEWAY_HALOPESA'),
        'BANK_TRANSFER' => env('PAYMENT_GATEWAY_BANK'),
    ]),

    'stub' => [
        'auto_success' => (bool) env('PAYMENT_STUB_AUTO_SUCCESS', false),
        'webhook_secret' => env('PAYMENT_STUB_WEBHOOK_SECRET', env('PAYMENT_WEBHOOK_SECRET', '')),
    ],

    /*
     | Selcom merchant account — customer payments settle here (SELCOM_VENDOR).
     | Webhook URL to register in Selcom dashboard:
     |   {APP_URL}/api/payments/webhooks/selcom
     */
    'selcom' => [
        'base_url' => env('SELCOM_BASE_URL', 'https://apigw.selcommobile.com'),
        'vendor' => env('SELCOM_VENDOR', ''),
        'api_key' => env('SELCOM_API_KEY', ''),
        'api_secret' => env('SELCOM_API_SECRET', ''),
        'webhook_allow_unsigned' => (bool) env('SELCOM_WEBHOOK_ALLOW_UNSIGNED', false),
    ],

    /*
     | Secrets for real providers — never expose to frontend.
     */
    'providers' => [
        'default' => [
            'api_url' => env('PAYMENT_API_URL'),
            'public_key' => env('PAYMENT_PUBLIC_KEY'),
            'secret_key' => env('PAYMENT_SECRET_KEY'),
            'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
        ],
    ],

    'commission' => [
        'default_platform_percent' => env('PAYMENT_DEFAULT_COMMISSION_PERCENT', '10'),
    ],

    /** Abandoned checkout (PENDING) older than this → EXPIRED */
    'pending_expiry_hours' => (int) env('PAYMENT_PENDING_EXPIRY_HOURS', 24),

];
