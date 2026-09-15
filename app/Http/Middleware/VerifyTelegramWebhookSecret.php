<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telegram обязан присылать secret_token в заголовке при каждом webhook-запросе
 * (ТЗ п.93). Без совпадения — запрос не от Telegram, отбрасываем сразу.
 */
class VerifyTelegramWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.telegram.webhook_secret');
        $received = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! $expected || ! hash_equals($expected, (string) $received)) {
            abort(403, 'Invalid webhook secret token');
        }

        return $next($request);
    }
}
