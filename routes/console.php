<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ТЗ п.105 — ежедневный бэкап БД. В проде нужен один cron-триггер на весь
// планировщик: `* * * * * php artisan schedule:run` (стандартный паттерн Laravel),
// см. README. onOneServer() — на случай если когда-нибудь app и queue будут
// в нескольких копиях, бэкап не продублируется.
Schedule::command('db:backup')->dailyAt('03:00')->onOneServer();

// ТЗ п.104 — мониторинг вебхука и очереди, алерт владельцу при проблеме.
Schedule::command('system:health-check')->everyFifteenMinutes()->onOneServer();

// Чистит telegram_updates/pending_inputs/order_drafts (см. Prunable-модели) —
// без этого таблицы растут бесконечно. После db:backup — бэкап снят до чистки.
Schedule::command('model:prune')->dailyAt('03:15')->onOneServer();
