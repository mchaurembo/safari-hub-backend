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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'vonage' => [
        'key'    => env('VONAGE_API_KEY', ''),
        'secret' => env('VONAGE_API_SECRET', ''),
        'from'   => env('VONAGE_FROM', 'CHAPA'),
    ],

    /*
     | WhatsApp via Meta Cloud API
     | Setup: https://developers.facebook.com → Create App → Business → Add WhatsApp
     */
    'whatsapp' => [
        'token'           => env('WHATSAPP_TOKEN', ''),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', ''),
        // Business-initiated messages must use an approved template (hello_world works in Meta test mode).
        'template_name'   => env('WHATSAPP_TEMPLATE_NAME', 'hello_world'),
        'template_lang'   => env('WHATSAPP_TEMPLATE_LANG', 'en_US'),
        // Set to 1 if your template has a single {{1}} body variable for the notification text.
        'template_body_params' => (int) env('WHATSAPP_TEMPLATE_BODY_PARAMS', 0),
    ],

    /*
     | Notification channel strategy:
     |   whatsapp_sms  → WhatsApp first, SMS fallback  (saves SMS quota)
     |   whatsapp      → WhatsApp only
     |   sms           → SMS only
     |   none          → email only
     */
    'notification_channel' => env('NOTIFICATION_CHANNEL', 'whatsapp_sms'),

];
