<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PriceDeclineReason;
use App\Models\Order;
use App\Models\PendingInput;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Согласование стоимости ремонта (ТЗ п.28-31): причина неисправности → работы →
 * цена работ → цена запчастей → показ итога → решение клиента. Ввод — через
 * PendingInput (kind=price_approval), решение клиента и его причина отказа —
 * через отдельный kind=price_decline_reason (аналог OrderDecisionFlow).
 */
class PriceApprovalFlow
{
    private const ENTRY_KIND = 'price_approval';

    private const DECLINE_KIND = 'price_decline_reason';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
        private readonly MasterOrderGuard $guard,
        private readonly MasterOrderScreen $screen,
    ) {}

    public function start(User $master, int $chatId, int $orderNumber): void
    {
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::PRICE_APPROVAL, $chatId);

        if (! $order) {
            return;
        }

        PendingInput::query()->updateOrCreate(
            ['user_id' => $master->id],
            [
                'kind' => self::ENTRY_KIND,
                'payload' => ['order_number' => $orderNumber, 'step' => 'failure_reason'],
                'expires_at' => now()->addMinutes(30),
            ]
        );

        $this->telegram->sendMessage($chatId, 'Причина неисправности:');
    }

    public function handleEntryText(User $master, int $chatId, PendingInput $pending, string $text): void
    {
        $text = trim($text);
        $step = $pending->get('step');

        match ($step) {
            'failure_reason' => $this->advanceEntry($pending, $chatId, 'work_needed', 'failure_reason', $text, 'Какие работы требуются:'),
            'work_needed' => $this->advanceEntry($pending, $chatId, 'labor_price', 'work_needed', $text, 'Стоимость работы, ₽:'),
            'labor_price' => $this->handlePrice($pending, $chatId, $text, 'labor_price', 'parts_price', 'Цена запчастей для клиента, ₽ (0, если не нужны):'),
            'parts_price' => $this->finishEntry($master, $chatId, $pending, $text),
            default => $pending->delete(),
        };
    }

    public function approve(User $master, int $chatId, int $orderNumber): void
    {
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::PRICE_APPROVAL, $chatId);

        if (! $order || $order->labor_price === null) {
            if ($order) {
                $this->telegram->sendMessage($chatId, 'Сначала укажи стоимость ремонта.');
            }

            return;
        }

        try {
            $order = $this->statusMachine->transition($order, OrderStatus::IN_PROGRESS, $master);
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $order->load('master');
        $this->telegram->sendMessage($chatId, "Заявка {$order->code()} переведена в работу.");
        $this->notifier->workStarted($order);
    }

    public function declineStart(User $master, int $chatId, int $orderNumber): void
    {
        if (! $this->guard->resolve($master, $orderNumber, OrderStatus::PRICE_APPROVAL, $chatId)) {
            return;
        }

        $buttons = array_map(
            fn (PriceDeclineReason $r) => [['text' => $r->label(), 'callback_data' => "order:price_decline_reason:{$orderNumber}:{$r->value}"]],
            PriceDeclineReason::cases()
        );

        $this->telegram->sendMessage($chatId, 'Причина отказа клиента:', ['inline_keyboard' => $buttons]);
    }

    public function declineReason(User $master, int $chatId, int $orderNumber, string $reasonCode): void
    {
        $reason = PriceDeclineReason::tryFrom($reasonCode);
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::PRICE_APPROVAL, $chatId);

        if (! $order || ! $reason) {
            return;
        }

        if ($reason === PriceDeclineReason::OTHER) {
            PendingInput::query()->updateOrCreate(
                ['user_id' => $master->id],
                [
                    'kind' => self::DECLINE_KIND,
                    'payload' => ['order_number' => $orderNumber],
                    'expires_at' => now()->addMinutes(15),
                ]
            );

            $this->telegram->sendMessage($chatId, 'Опиши причину отказа своими словами:');

            return;
        }

        $this->applyDecline($master, $chatId, $order, $reason->label());
    }

    public function declineCustomReasonText(User $master, int $chatId, PendingInput $pending, string $text): void
    {
        $orderNumber = (int) $pending->get('order_number');
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::PRICE_APPROVAL, $chatId);
        $pending->delete();

        if (! $order) {
            return;
        }

        $this->applyDecline($master, $chatId, $order, 'Другое: '.trim($text));
    }

    private function advanceEntry(PendingInput $pending, int $chatId, string $nextStep, string $field, string $value, string $prompt): void
    {
        $pending->put($field, $value);
        $pending->put('step', $nextStep);
        $pending->save();

        $this->telegram->sendMessage($chatId, $prompt);
    }

    private function handlePrice(PendingInput $pending, int $chatId, string $text, string $field, string $nextStep, string $prompt): void
    {
        $value = $this->parsePrice($text);

        if ($value === null) {
            $this->telegram->sendMessage($chatId, 'Введи число рублей, например 4500:');

            return;
        }

        $pending->put($field, $value);
        $pending->put('step', $nextStep);
        $pending->save();

        $this->telegram->sendMessage($chatId, $prompt);
    }

    private function finishEntry(User $master, int $chatId, PendingInput $pending, string $text): void
    {
        $partsPrice = $this->parsePrice($text);

        if ($partsPrice === null) {
            $this->telegram->sendMessage($chatId, 'Введи число рублей, 0 если запчасти не нужны:');

            return;
        }

        $orderNumber = (int) $pending->get('order_number');
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::PRICE_APPROVAL, $chatId);
        $pending->delete();

        if (! $order) {
            return;
        }

        $laborPrice = (int) $pending->get('labor_price');
        $estimated = $laborPrice + $partsPrice;

        $order->update([
            'failure_reason' => $pending->get('failure_reason'),
            'work_needed' => $pending->get('work_needed'),
            'labor_price' => $laborPrice,
            'parts_sell_price' => $partsPrice,
            'estimated_price' => $estimated,
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "Стоимость клиенту:\nРаботы: {$laborPrice} ₽\nЗапчасти: {$partsPrice} ₽\nИтого: {$estimated} ₽",
            ['inline_keyboard' => [[
                ['text' => '✅ Клиент согласовал', 'callback_data' => "order:price_approve:{$order->number}"],
                ['text' => '❌ Клиент отказался', 'callback_data' => "order:price_decline:{$order->number}"],
            ]]]
        );
    }

    private function applyDecline(User $master, int $chatId, Order $order, string $reasonLabel): void
    {
        try {
            $order = $this->statusMachine->transition($order, OrderStatus::CUSTOMER_CANCELLED, $master, comment: $reasonLabel);
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $order->load('master');
        $this->telegram->sendMessage($chatId, "Отказ клиента по заявке {$order->code()} зафиксирован.");
        $this->notifier->customerCancelled($order, $reasonLabel);
    }

    private function parsePrice(string $text): ?int
    {
        $text = trim($text);

        if (! ctype_digit($text)) {
            return null;
        }

        return (int) $text;
    }
}
