<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Защита в глубину поверх secret_token/internal API key — сами по себе
        // они уже отсекают неавторизованные запросы, но не ограничивают объём.
        // Лимиты щедрые: цель не подрезать легитимные всплески (альбом фото,
        // одновременные сообщения от нескольких клиентов), а остановить явный
        // флуд до того, как он дойдёт до обработки апдейта/транзакции.
        RateLimiter::for(
            'telegram-webhook',
            fn (Request $request) => Limit::perMinute(300)->by($request->ip())
        );

        RateLimiter::for(
            'internal-api',
            // По ключу, а не по IP — разные клиенты Internal API (пока только
            // будущая CRM) не должны делить один лимит просто из-за общего прокси.
            fn (Request $request) => Limit::perMinute(60)
                ->by($request->header('X-Internal-Api-Key') ?: $request->ip())
        );
    }
}
