<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Services\Telegram\UpdateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Точка входа для Telegram-апдейтов в продакшне/staging (ТЗ п.93 — HTTPS + secret_token).
 * Сама логика обработки — в UpdateHandler, общей с командой polling для локальной разработки.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly UpdateHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->handler->handle($request->all());

        // Telegram важен только статус 200 — иначе апдейт придёт повторно.
        return response()->json(['ok' => true]);
    }
}
