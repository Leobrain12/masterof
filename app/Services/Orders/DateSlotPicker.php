<?php

namespace App\Services\Orders;

use App\Models\TimeSlot;
use App\Services\Telegram\TelegramClient;
use Carbon\Carbon;

/**
 * Общий рендер + разбор шагов "дата → слот" — использовался трижды (создание заявки,
 * перенос, теперь планирование повторного визита в фазе 03). Первые два раза дублирование
 * было осознанным (см. vault/Решения.md), на третий — уже реальный повод вынести:
 * это единственное место, где парсится "ДД.ММ", и именно тут был баг с
 * Carbon::createFromFormat() в фазе 02 — одна точка вместо трёх снижает риск, что
 * фикс попадёт не везде.
 *
 * OrderDraftFlow продолжает использовать собственную реализацию — она уже проверена
 * тестами Фазы 01, и рефакторинг ради унификации не стоил риска регрессии.
 */
class DateSlotPicker
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function promptDate(int $chatId, string $callbackPrefix): void
    {
        $this->telegram->sendMessage($chatId, 'Дата визита:', [
            'inline_keyboard' => [[
                ['text' => 'Сегодня', 'callback_data' => "{$callbackPrefix}:date:today"],
                ['text' => 'Завтра', 'callback_data' => "{$callbackPrefix}:date:tomorrow"],
                ['text' => 'Послезавтра', 'callback_data' => "{$callbackPrefix}:date:day_after"],
            ], [
                ['text' => 'Выбрать дату', 'callback_data' => "{$callbackPrefix}:date:custom"],
            ]],
        ]);
    }

    public function promptCustomDate(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, 'Введи дату в формате ДД.ММ (например 16.08):');
    }

    public function resolveQuickDate(string $value): ?string
    {
        $map = ['today' => 0, 'tomorrow' => 1, 'day_after' => 2];

        return array_key_exists($value, $map) ? now()->addDays($map[$value])->toDateString() : null;
    }

    /**
     * @param  bool  $preferFuture  true — дата без года считается предстоящей (перенос,
     *                               визит: "04.09" раньше сегодняшнего — значит, это уже
     *                               04.09 следующего года), явный прошлый год — ошибка.
     *                               false — наоборот: даты не подкручиваются вперёд,
     *                               прошлые допустимы как есть (ввод периода статистики,
     *                               где даты почти всегда в прошлом).
     * @return string|null  ISO-дата (Y-m-d), либо null если разобрать не удалось
     */
    public function parseCustomDate(string $text, bool $preferFuture = true): ?string
    {
        $text = trim($text);

        foreach (['d.m.Y', 'd.m.y', 'd.m'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $text);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if ($date === false) {
                continue;
            }

            if (! str_contains($format, 'Y') && ! str_contains($format, 'y')) {
                $date->year(now()->year);

                if ($preferFuture && $date->isPast() && ! $date->isToday()) {
                    $date->addYear();
                }
            } elseif ($preferFuture && $date->isPast() && ! $date->isToday()) {
                return null;
            }

            return $date->startOfDay()->toDateString();
        }

        return null;
    }

    public function promptSlot(int $chatId, string $callbackPrefix): void
    {
        $slots = TimeSlot::query()->where('is_active', true)->orderBy('sort_order')->get();
        $buttons = $slots->map(fn ($s) => ['text' => $s->label, 'callback_data' => "{$callbackPrefix}:slot:{$s->id}"])->all();
        $buttons[] = ['text' => 'Другое', 'callback_data' => "{$callbackPrefix}:slot:custom"];

        $this->telegram->sendMessage($chatId, 'Временной слот:', ['inline_keyboard' => array_chunk($buttons, 2)]);
    }

    public function promptCustomSlot(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, 'Укажи время текстом (например 19:00–20:00):');
    }

    public function resolveSlotId(string $value): ?TimeSlot
    {
        return ctype_digit($value) ? TimeSlot::query()->find((int) $value) : null;
    }
}
