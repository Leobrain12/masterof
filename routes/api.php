<?php

use App\Http\Controllers\Api\MasterController;
use App\Http\Controllers\Api\OrderAssignController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderMediaController;
use App\Http\Controllers\Api\OrderPaymentController;
use App\Http\Controllers\Api\OrderTransitionController;
use App\Http\Controllers\Api\OrderVisitController;
use App\Http\Controllers\Api\OrderWorkReportController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Telegram\WebhookController;
use App\Http\Middleware\VerifyInternalApiKey;
use App\Http\Middleware\VerifyTelegramWebhookSecret;
use Illuminate\Support\Facades\Route;

// Telegram шлёт сюда все апдейты (сообщения, нажатия кнопок).
// Не под /api/v1 — это не часть Internal API из ТЗ п.88, а отдельный вход бота.
Route::post('/telegram/webhook', WebhookController::class)
    ->middleware(['throttle:telegram-webhook', VerifyTelegramWebhookSecret::class]);

// Internal API (ТЗ п.88) — приём заказов от CRM/сайта и обратная связь по ним.
Route::prefix('v1')->middleware(['throttle:internal-api', VerifyInternalApiKey::class])->group(function () {
    // /orders/search — литеральный маршрут ПЕРЕД /orders/{order}, иначе "search"
    // пытается забиндиться как {order} (не найдётся, 404, а не поиск).
    Route::get('/orders/search', [OrderController::class, 'search']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::patch('/orders/{order}', [OrderController::class, 'update']);
    Route::post('/orders/{order}/assign', OrderAssignController::class);
    Route::post('/orders/{order}/transition', OrderTransitionController::class);
    Route::post('/orders/{order}/visits', OrderVisitController::class);
    Route::post('/orders/{order}/work-report', OrderWorkReportController::class);
    Route::post('/orders/{order}/media', OrderMediaController::class);
    Route::post('/orders/{order}/payments', OrderPaymentController::class);
    Route::get('/masters', [MasterController::class, 'index']);
    Route::get('/masters/{master}/stats', [MasterController::class, 'stats']);
    Route::get('/stats', StatsController::class);
});
