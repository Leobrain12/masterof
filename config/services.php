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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'owner_id' => env('TELEGRAM_OWNER_ID'),
        // Некоторые хостинги не пускают наружу напрямую до api.telegram.org
        // (реальный случай — см. vault/Решения.md), тогда нужен прокси.
        // Пусто по умолчанию — прямое соединение работает в большинстве случаев.
        'proxy' => [
            'host' => env('TELEGRAM_PROXY_HOST'),
            'port' => env('TELEGRAM_PROXY_PORT'),
            'type' => env('TELEGRAM_PROXY_TYPE', 'socks5h'),
            // Опционально — публичные прокси часто требуют логин/пароль.
            'username' => env('TELEGRAM_PROXY_USERNAME'),
            'password' => env('TELEGRAM_PROXY_PASSWORD'),
        ],
    ],

    'internal_api' => [
        'key' => env('INTERNAL_API_KEY'),
    ],

    'crm' => [
        // Реальной CRM ещё нет (см. vault/Решения.md) — по умолчанию адаптер
        // только логирует снимок заказа. Когда появится Bitrix24/amoCRM/другая,
        // сюда подставляется полный класс нового адаптера, без изменений в коде.
        //
        // ?: , не второй аргумент env() — CRM_ADAPTER_CLASS в .env.example
        // намеренно оставлен пустым как "используй дефолт", но env('X', $default)
        // отдаёт $default только когда переменной нет вовсе, а не когда она
        // пустая строка — с пустым значением получили бы app()->make(''),
        // BindingResolutionException. Поймано полным прогоном тестов, не постфактум.
        'adapter' => env('CRM_ADAPTER_CLASS') ?: \App\Services\Crm\LogCrmAdapter::class,
    ],

];
