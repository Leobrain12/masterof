<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\OrderVisit;
use App\Models\PendingInput;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Str;

/**
 * Повторный визит (ТЗ п.33-34) — не новый Order, а запись в order_visits плюс
 * заказ возвращается в поле (WAITING_PART → ACCEPTED) с новыми датой/слотом.
 * Visit #1 создаётся при создании заказа (см. OrderDraftFlow); эта команда
 * создаёт Visit #2, #3, ... и закрывает предыдущий (status=COMPLETED).
 */
class OrderVisitFlow
{
    private const KIND = 'next_visit';

    private const PREFIX = 'visit';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
        private readonly MasterOrderGuard $guard,
        private readonly DateSlotPicker $picker,
    ) {}

    public function start(User $master, int $chatId, int $orderNumber): void
    {
        if (! $this->guard->resolve($master, $orderNumber, OrderStatus::WAITING_PART, $chatId)) {
            return;
        }

        PendingInput::query()->updateOrCreate(
            ['user_id' => $master->id],
            [
                'kind' => self::KIND,
                'payload' => ['order_number' => $orderNumber, 'step' => 'date'],
                'expires_at' => now()->addMinutes(20),
            ]
        );

        $this->picker->promptDate($chatId, self::PREFIX);
    }

    public function handle(User $master, PendingInput $pending, int $chatId, ?string $text, ?string $callback): void
    {
        match ($pending->get('step')) {
            'date' => $this->handleDate($pending, $chatId, $callback),
            'date_custom' => $this->handleDateCustom($pending, $chatId, $text),
            'slot' => $this->handleSlot($master, $pending, $chatId, $callback),
            'slot_custom' => $this->handleSlotCustom($master, $pending, $chatId, $text),
            default => $pending->delete(),
        };
    }

    private function handleDate(PendingInput $pending, int $chatId, ?string $callback): void
    {
        $value = $callback ? Str::after($callback, 'date:') : null;

        if ($value === 'custom') {
            $pending->put('step', 'date_custom');
            $pending->save();
            $this->picker->promptCustomDate($chatId);

            return;
        }

        $date = $value ? $this->picker->resolveQuickDate($value) : null;

        if (! $date) {
            $this->picker->promptDate($chatId, self::PREFIX);

            return;
        }

        $pending->put('visit_date', $date);
        $pending->put('step', 'slot');
        $pending->save();
        $this->picker->promptSlot($chatId, self::PREFIX);
    }

    private function handleDateCustom(PendingInput $pending, int $chatId, ?string $text): void
    {
        $date = $text ? $this->picker->parseCustomDate($text) : null;

        if (! $date) {
            $this->telegram->sendMessage($chatId, 'Не получилось разобрать дату. Формат ДД.ММ, например 16.08:');

            return;
        }

        $pending->put('visit_date', $date);
        $pending->put('step', 'slot');
        $pending->save();
        $this->picker->promptSlot($chatId, self::PREFIX);
    }

    private function handleSlot(User $master, PendingInput $pending, int $chatId, ?string $callback): void
    {
        $value = $callback ? Str::after($callback, 'slot:') : null;

        if ($value === 'custom') {
            $pending->put('step', 'slot_custom');
            $pending->save();
            $this->picker->promptCustomSlot($chatId);

            return;
        }

        $slot = $value ? $this->picker->resolveSlotId($value) : null;

        if (! $slot) {
            $this->picker->promptSlot($chatId, self::PREFIX);

            return;
        }

        $this->apply($master, $pending, $chatId, $slot->label);
    }

    private function handleSlotCustom(User $master, PendingInput $pending, int $chatId, ?string $text): void
    {
        if (! $text || trim($text) === '') {
            $this->picker->promptCustomSlot($chatId);

            return;
        }

        $this->apply($master, $pending, $chatId, trim($text));
    }

    private function apply(User $master, PendingInput $pending, int $chatId, string $timeSlotLabel): void
    {
        $orderNumber = (int) $pending->get('order_number');
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::WAITING_PART, $chatId);
        $visitDate = $pending->get('visit_date');
        $pending->delete();

        if (! $order) {
            return;
        }

        $order->visits()->where('status', 'SCHEDULED')->update([
            'status' => 'COMPLETED',
            'completed_at' => now(),
        ]);

        $nextNumber = (int) ($order->visits()->max('visit_number') ?? 0) + 1;

        OrderVisit::query()->create([
            'order_id' => $order->id,
            'visit_number' => $nextNumber,
            'visit_date' => $visitDate,
            'time_slot_label' => $timeSlotLabel,
            'master_id' => $order->master_id,
            'status' => 'SCHEDULED',
            'reason' => 'Установка детали: '.$order->part_name,
        ]);

        try {
            $order = $this->statusMachine->transition(
                $order,
                OrderStatus::ACCEPTED,
                $master,
                comment: "Визит #{$nextNumber} запланирован",
                attributes: [
                    'visit_date' => $visitDate,
                    'time_slot_label' => $timeSlotLabel,
                ],
            );
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $order->load('master');
        $this->telegram->sendMessage($chatId, "Визит #{$nextNumber} по заявке {$order->code()} запланирован.");
        $this->notifier->visitScheduled($order, $nextNumber);
    }
}
