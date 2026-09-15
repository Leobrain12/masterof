<?php

namespace App\Services\Orders;

use App\Models\Master;
use App\Models\PendingInput;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Статистика по периодам (ТЗ п.73, 76-77) — один и тот же выбор периода
 * для ADMIN ("Статистика", сводка + по мастерам) и MASTER ("Моя статистика",
 * только свои цифры). Какой экран показать — решает роль пользователя,
 * нажавшего кнопку, а не то, из какого меню он в неё попал.
 */
class StatsFlow
{
    private const KIND = 'stats_period';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly DateSlotPicker $picker,
        private readonly MasterStatsCalculator $masterStats,
        private readonly OrderStatsCalculator $orderStats,
    ) {}

    public function promptPeriod(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, 'За какой период?', [
            'inline_keyboard' => [[
                ['text' => 'Сегодня', 'callback_data' => 'stats:today'],
                ['text' => 'Вчера', 'callback_data' => 'stats:yesterday'],
            ], [
                ['text' => '7 дней', 'callback_data' => 'stats:7d'],
                ['text' => '30 дней', 'callback_data' => 'stats:30d'],
            ], [
                ['text' => 'Выбрать период', 'callback_data' => 'stats:custom'],
            ]],
        ]);
    }

    public function handlePeriod(User $user, int $chatId, string $code): void
    {
        if ($code === 'custom') {
            PendingInput::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['kind' => self::KIND, 'payload' => ['step' => 'from'], 'expires_at' => now()->addMinutes(10)]
            );

            $this->telegram->sendMessage($chatId, 'Дата начала периода (ДД.ММ):');

            return;
        }

        $range = $this->resolveQuickPeriod($code);

        if (! $range) {
            $this->promptPeriod($chatId);

            return;
        }

        [$from, $to, $label] = $range;

        $this->render($user, $chatId, $from, $to, $label);
    }

    public function handleCustomText(User $user, int $chatId, PendingInput $pending, string $text): void
    {
        if ($pending->get('step') === 'from') {
            $date = $this->picker->parseCustomDate($text, preferFuture: false);

            if (! $date) {
                $this->telegram->sendMessage($chatId, 'Не получилось разобрать дату. Формат ДД.ММ, например 01.08:');

                return;
            }

            $pending->put('from', $date);
            $pending->put('step', 'to');
            $pending->save();

            $this->telegram->sendMessage($chatId, 'Дата окончания периода (ДД.ММ):');

            return;
        }

        $toDate = $this->picker->parseCustomDate($text, preferFuture: false);

        if (! $toDate) {
            $this->telegram->sendMessage($chatId, 'Не получилось разобрать дату. Формат ДД.ММ, например 07.08:');

            return;
        }

        $from = Carbon::parse($pending->get('from'))->startOfDay();
        $to = Carbon::parse($toDate)->endOfDay();
        $pending->delete();

        if ($to->lt($from)) {
            $this->telegram->sendMessage($chatId, 'Дата окончания раньше даты начала. Начни заново — «Статистика».');

            return;
        }

        $label = $from->format('d.m.Y').' – '.$to->format('d.m.Y');
        $this->render($user, $chatId, $from, $to, $label);
    }

    private function render(User $user, int $chatId, CarbonInterface $from, CarbonInterface $to, string $label): void
    {
        if ($user->role->isAdminLike()) {
            $this->renderAdmin($chatId, $from, $to, $label);

            return;
        }

        $this->renderMaster($user, $chatId, $from, $to, $label);
    }

    private function renderMaster(User $user, int $chatId, CarbonInterface $from, CarbonInterface $to, string $label): void
    {
        $master = $user->master;

        if (! $master) {
            $this->telegram->sendMessage($chatId, 'Ты не привязан ни к одному профилю мастера.');

            return;
        }

        $s = $this->masterStats->calculate($master, $from, $to);

        $lines = [
            "Мастер: {$master->name}",
            $label,
            "Назначено: {$s['assigned']}",
            "Принято: {$s['accepted']}",
            "Выполнено: {$s['completed']}",
            "Отказов: {$s['declined']}",
            'Completion rate: '.($s['completion_rate'] !== null ? "{$s['completion_rate']}%" : 'N/A'),
            "Выручка: {$s['revenue']} ₽",
            "Средний чек: {$s['avg_check']} ₽",
            "Себестоимость деталей: {$s['parts_cost']} ₽",
            "Выплата мастеру: {$s['master_payout']} ₽",
            "Гарантийных: 0",
        ];

        $this->telegram->sendMessage($chatId, implode("\n", $lines));
    }

    private function renderAdmin(int $chatId, CarbonInterface $from, CarbonInterface $to, string $label): void
    {
        $overall = $this->orderStats->calculate($from, $to);

        $lines = [
            $label,
            "Новых заказов: {$overall['new_orders']}",
            "Выполнено: {$overall['completed']}",
            "Выручка: {$overall['revenue']} ₽",
            "Средний чек: {$overall['avg_check']} ₽",
            "Отказов клиента: {$overall['customer_cancelled']}",
            "Отказов мастеров: {$overall['master_declines']}",
            "Ожидают деталь: {$overall['waiting_parts']}",
        ];

        $this->telegram->sendMessage($chatId, implode("\n", $lines));

        $masters = Master::query()->where('is_active', true)->orderBy('name')->get();

        if ($masters->isEmpty()) {
            return;
        }

        $byMasterLines = ["По мастерам ({$label}):"];

        foreach ($masters as $master) {
            $s = $this->masterStats->calculate($master, $from, $to);
            $rate = $s['completion_rate'] !== null ? "{$s['completion_rate']}%" : 'N/A';
            $byMasterLines[] = "{$master->name} — назначено {$s['assigned']}, выполнено {$s['completed']}, CR {$rate}, выручка {$s['revenue']} ₽";
        }

        $this->telegram->sendMessage($chatId, implode("\n", $byMasterLines));
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface, 2: string}|null
     */
    private function resolveQuickPeriod(string $code): ?array
    {
        return match ($code) {
            'today' => [now()->startOfDay(), now()->endOfDay(), 'Сегодня'],
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay(), 'Вчера'],
            '7d' => [now()->subDays(6)->startOfDay(), now()->endOfDay(), '7 дней'],
            '30d' => [now()->subDays(29)->startOfDay(), now()->endOfDay(), '30 дней'],
            default => null,
        };
    }
}
