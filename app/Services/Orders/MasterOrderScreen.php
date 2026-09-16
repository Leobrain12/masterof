<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Carbon\CarbonInterface;

/**
 * Единый экран активной заявки мастера (ТЗ п.23, F13) — состав кнопок зависит
 * от текущего статуса, а не от отдельного экрана на каждый статус.
 */
class MasterOrderScreen
{
    /**
     * @var list<OrderStatus>
     */
    private const ACTIVE_STATUSES = [
        OrderStatus::ACCEPTED,
        OrderStatus::ON_THE_WAY,
        OrderStatus::ARRIVED,
        OrderStatus::DIAGNOSTICS,
        OrderStatus::PRICE_APPROVAL,
        OrderStatus::IN_PROGRESS,
        OrderStatus::WAITING_PART,
    ];

    /**
     * Расписание на день (today/tomorrow) не должно включать заявки, от
     * которых этот же мастер отказался или которые закрыты не в его пользу —
     * master_id ОСТАЁТСЯ на отказавшемся мастере и после MASTER_DECLINED
     * (см. vault/Решения.md#«Назначена» vs «переназначена»), иначе отказанная
     * заявка утекла бы обратно в собственное «Сегодня» этого же мастера.
     *
     * @var list<OrderStatus>
     */
    private const SCHEDULE_EXCLUDED_STATUSES = [
        OrderStatus::MASTER_DECLINED,
        OrderStatus::CUSTOMER_CANCELLED,
        OrderStatus::UNREPAIRABLE,
        OrderStatus::NO_CONTACT,
        OrderStatus::CANCELLED,
    ];

    /**
     * @var list<OrderStatus>
     */
    private const HISTORY_STATUSES = [
        OrderStatus::COMPLETED,
        OrderStatus::PAID,
    ];

    private const HISTORY_LIMIT = 20;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderCardFormatter $formatter,
    ) {}

    public function myActive(User $masterUser, int $chatId): void
    {
        $master = $masterUser->master;

        if (! $master) {
            $this->telegram->sendMessage($chatId, 'Ты не привязан ни к одному профилю мастера.');

            return;
        }

        $orders = Order::query()
            ->with(['applianceType', 'brand', 'master', 'warrantyParent'])
            ->where('master_id', $master->id)
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, self::ACTIVE_STATUSES))
            ->orderBy('visit_date')
            ->get();

        if ($orders->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Активных заказов нет.');

            return;
        }

        foreach ($orders as $order) {
            $this->sendCard($chatId, $order);
        }
    }

    public function today(User $masterUser, int $chatId): void
    {
        $this->sendSchedule($masterUser, $chatId, now(), 'На сегодня заявок нет.');
    }

    public function tomorrow(User $masterUser, int $chatId): void
    {
        $this->sendSchedule($masterUser, $chatId, now()->addDay(), 'На завтра заявок нет.');
    }

    public function history(User $masterUser, int $chatId): void
    {
        $master = $masterUser->master;

        if (! $master) {
            $this->telegram->sendMessage($chatId, 'Ты не привязан ни к одному профилю мастера.');

            return;
        }

        $orders = Order::query()
            ->with(['applianceType', 'brand', 'master', 'warrantyParent'])
            ->where('master_id', $master->id)
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, self::HISTORY_STATUSES))
            ->orderByDesc('visit_date')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        if ($orders->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'История пуста.');

            return;
        }

        foreach ($orders as $order) {
            $this->sendCard($chatId, $order);
        }
    }

    private function sendSchedule(User $masterUser, int $chatId, CarbonInterface $date, string $emptyMessage): void
    {
        $master = $masterUser->master;

        if (! $master) {
            $this->telegram->sendMessage($chatId, 'Ты не привязан ни к одному профилю мастера.');

            return;
        }

        $orders = Order::query()
            ->with(['applianceType', 'brand', 'master', 'warrantyParent'])
            ->where('master_id', $master->id)
            // whereDate(), не where() — visit_date хранится как полный datetime
            // (формат SQLite-грамматики по умолчанию для date-cast), точное
            // строковое сравнение с "Y-m-d" никогда бы не совпало.
            ->whereDate('visit_date', $date->toDateString())
            ->whereNotIn('status', array_map(fn (OrderStatus $s) => $s->value, self::SCHEDULE_EXCLUDED_STATUSES))
            ->orderBy('time_slot_label')
            ->get();

        if ($orders->isEmpty()) {
            $this->telegram->sendMessage($chatId, $emptyMessage);

            return;
        }

        foreach ($orders as $order) {
            $this->sendCard($chatId, $order);
        }
    }

    public function sendCard(int $chatId, Order $order): void
    {
        $this->telegram->sendMessage($chatId, $this->formatter->format($order), $this->actionsFor($order));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function actionsFor(Order $order): ?array
    {
        $n = $order->number;

        $rows = match ($order->status) {
            OrderStatus::ACCEPTED => [
                [['🚗 Выехал', "order:depart:{$n}"]],
                [['🔁 Перенести', "order:reschedule:{$n}"], ['📵 Не дозвонился', "order:no_contact:{$n}"]],
            ],
            OrderStatus::ON_THE_WAY => [
                [['📍 На месте', "order:arrive:{$n}"]],
                [['🔁 Перенести', "order:reschedule:{$n}"], ['📵 Не дозвонился', "order:no_contact:{$n}"]],
            ],
            OrderStatus::ARRIVED => [
                [['🔍 Начать диагностику', "order:diagnose:{$n}"]],
            ],
            OrderStatus::DIAGNOSTICS => [
                [['✅ Можно ремонтировать', "order:diagnosis:{$n}:REPAIRABLE"]],
                [['🔧 Нужна деталь', "order:diagnosis:{$n}:NEED_PART"]],
                [['🚫 Ремонт нецелесообразен', "order:diagnosis:{$n}:UNREPAIRABLE"]],
                [['❌ Клиент отказался', "order:diagnosis:{$n}:CUSTOMER_DECLINED"]],
            ],
            OrderStatus::PRICE_APPROVAL => $order->labor_price === null
                ? [[['💰 Указать стоимость', "order:price_start:{$n}"]]]
                : [[['✅ Клиент согласовал', "order:price_approve:{$n}"], ['❌ Клиент отказался', "order:price_decline:{$n}"]]],
            OrderStatus::WAITING_PART => [
                [['📦 Деталь получена — назначить визит', "order:visit_next:{$n}"]],
            ],
            OrderStatus::IN_PROGRESS => [
                [['📷 Добавить медиа', "order:add_media:{$n}"], ['✅ Завершить ремонт', "order:complete:{$n}"]],
            ],
            default => [],
        };

        if ($order->media()->exists()) {
            $rows[] = [['🖼 Медиа', "order:view_media:{$n}"]];
        }

        return $rows ? $this->rows($rows) : null;
    }

    /**
     * @param  list<list<array{0: string, 1: string}>>  $rows
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function rows(array $rows): array
    {
        return [
            'inline_keyboard' => array_map(
                fn (array $row) => array_map(fn (array $btn) => ['text' => $btn[0], 'callback_data' => $btn[1]], $row),
                $rows
            ),
        ];
    }
}
