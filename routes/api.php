<?php

use App\Http\Controllers\Api\OrderTransitionController;
use App\Http\Controllers\Telegram\WebhookController;
use App\Http\Middleware\VerifyInternalApiKey;
use App\Http\Middleware\VerifyTelegramWebhookSecret;
use Illuminate\Support\Facades\Route;

// Telegram шлёт сюда все апдейты (сообщения, нажатия кнопок).
// Не под /api/v1 — это не часть Internal API из ТЗ п.88, а отдельный вход бота.
Route::post('/telegram/webhook', WebhookController::class)
    ->middleware(VerifyTelegramWebhookSecret::class);

// Internal API (ТЗ п.88) — наполняется по мере фаз, сейчас только transition.
Route::prefix('v1')->middleware(VerifyInternalApiKey::class)->group(function () {
    Route::post('/orders/{order}/transition', OrderTransitionController::class);
});
