<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\RescheduleReason;
use App\Models\OrderStatusHistory;
use App\Models\PendingInput;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Str;

/**
 * Перенос заказа (ТЗ п.35) — новая дата, новый слот, обязательная причина,
 * история сохраняется. Не меняет status заказа — перенос это событие, а не
 * состояние (см. vault/Решения.md → "Перенос не меняет статус").
 *
 * Мини-FSM через PendingInput (kind=reschedule), шаги: date → [date_custom] →
 * slot → [slot_custom] → reason → [reason_custom] → применить.
 */
class OrderRescheduleFlow
{
    private const KIND = 'reschedule';

    private const PREFIX = 'resched';

    /**
     * @var list<OrderStatus>
     */
    private const APPLICABLE_STATUSES = [OrderStatus::ACCEPTED, OrderStatus::ON_THE_WAY];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderNotifier $notifier,
        private readonly MasterOrderGuard $guard,
        private readonly DateSlotPicker $picker,
    ) {}

    public function start(User $master, int $chatId, int $orderNumber): void
    {
        if (! $this->guard->resolveAny($master, $orderNumber, self::APPLICABLE_STATUSES, $chatId)) {
            return;
        }

        PendingInput::query()->updateOrCreate(
            ['user_id' => $master->id],
            [
                'kind' => self::KIND,
                'payload' => ['order_number' => $orderNumber, 'step' => 'date'],
                'expires_at' => now()->addMinutes(15),
            ]
        );

        $this->picker->promptDate($chatId, self::PREFIX);
    }

    public function handle(User $master, PendingInput $pending, int $chatId, ?string $text, ?string $callback): void
    {
        match ($pending->get('step')) {
            'date' => $this->handleDate($pending, $chatId, $callback),
            'date_custom' => $this->handleDateCustom($pending, $chatId, $text),
            'slot' => $this->handleSlot($pending, $chatId, $callback),
            'slot_custom' => $this->handleSlotCustom($pending, $chatId, $text),
            'reason' => $this->handleReason($master, $pending, $chatId, $callback),
            'reason_custom' => $this->handleReasonCustom($master, $pending, $chatId, $text),
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

    private function handleSlot(PendingInput $pending, int $chatId, ?string $callback): void
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

        $pending->put('time_slot_label', $slot->label);
        $pending->put('step', 'reason');
        $pending->save();
        $this->promptReason($chatId);
    }

    private function handleSlotCustom(PendingInput $pending, int $chatId, ?string $text): void
    {
        if (! $text || trim($text) === '') {
            $this->picker->promptCustomSlot($chatId);

            return;
        }

        $pending->put('time_slot_label', trim($text));
        $pending->put('step', 'reason');
        $pending->save();
        $this->promptReason($chatId);
    }

    private function promptReason(int $chatId): void
    {
        $buttons = array_map(
            fn (RescheduleReason $r) => [['text' => $r->label(), 'callback_data' => "resched:reason:{$r->value}"]],
            RescheduleReason::cases()
        );

        $this->telegram->sendMessage($chatId, 'Причина переноса:', ['inline_keyboard' => $buttons]);
    }

    private function handleReason(User $master, PendingInput $pending, int $chatId, ?string $callback): void
    {
        $value = $callback ? Str::after($callback, 'reason:') : null;
        $reason = $value ? RescheduleReason::tryFrom($value) : null;

        if (! $reason) {
            $this->promptReason($chatId);

            return;
        }

        if ($reason === RescheduleReason::OTHER) {
            $pending->put('step', 'reason_custom');
            $pending->save();
            $this->telegram->sendMessage($chatId, 'Опиши причину переноса своими словами:');

            return;
        }

        $this->apply($master, $pending, $chatId, $reason->label());
    }

    private function handleReasonCustom(User $master, PendingInput $pending, int $chatId, ?string $text): void
    {
        if (! $text || trim($text) === '') {
            $this->telegram->sendMessage($chatId, 'Опиши причину переноса своими словами:');

            return;
        }

        $this->apply($master, $pending, $chatId, 'Другое: '.trim($text));
    }

    private function apply(User $master, PendingInput $pending, int $chatId, string $reasonLabel): void
    {
        $orderNumber = (int) $pending->get('order_number');
        $order = $this->guard->resolveAny($master, $orderNumber, self::APPLICABLE_STATUSES, $chatId);
        $pending->delete();

        if (! $order) {
            return;
        }

        $oldDate = $order->visit_date->translatedFormat('d.m.Y');
        $oldSlot = $order->time_slot_label;

        $order->update([
            'visit_date' => $pending->get('visit_date'),
            'time_slot_label' => $pending->get('time_slot_label'),
            'time_slot_id' => null,
        ]);

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'old_status' => $order->status,
            'new_status' => $order->status,
            'changed_by_user_id' => $master->id,
            'comment' => "Перенос: {$oldDate} {$oldSlot} → {$order->visit_date->translatedFormat('d.m.Y')} {$order->time_slot_label}. Причина: {$reasonLabel}",
        ]);

        $this->telegram->sendMessage($chatId, "Заявка {$order->code()} перенесена.");
        $this->notifier->rescheduled($order->load('master'), $oldDate, $oldSlot, $reasonLabel);
    }
}
