<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // За Traefik'ом (Dokploy) или другим обратным прокси Laravel по умолчанию
        // не доверяет X-Forwarded-* заголовкам — Host/scheme запроса разбираются
        // как есть, что в контейнерном деплое ведёт к ошибкам вида "Invalid URI:
        // Host is malformed" и неверной генерации https-ссылок. '*' — доверяем
        // прокси с любого IP: сам контейнер снаружи недостижим напрямую (см.
        // docker/php/Dockerfile.dokploy — expose, не публикация порта), доверенный
        // прокси — единственный, кто вообще может достучаться, IP заранее не known.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Без SENTRY_LARAVEL_DSN в .env — SDK сам ничего не отправляет (см. config/sentry.php),
        // так что это безопасно подключать всегда, а не только когда DSN реально задан.
        Integration::handles($exceptions);
    })->create();
