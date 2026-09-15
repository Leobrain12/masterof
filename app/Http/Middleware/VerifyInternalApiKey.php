<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal API (ТЗ п.88) не публичный — сейчас у него нет других вызывающих,
 * кроме будущей CRM-интеграции (фаза 08), но эндпоинт уже существует, так что
 * защищаем сразу простым статическим ключом. Полноценная авторизация (OAuth/HMAC)
 * имеет смысл только когда появится реальный внешний потребитель.
 */
class VerifyInternalApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.internal_api.key');
        $received = $request->header('X-Internal-Api-Key');

        if (! $expected || ! hash_equals($expected, (string) $received)) {
            abort(401, 'Invalid internal API key');
        }

        return $next($request);
    }
}
