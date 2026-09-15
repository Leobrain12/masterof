<?php

namespace App\Services\Orders;

use App\Enums\MediaStage;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderMedia;
use App\Models\PendingInput;
use App\Models\User;
use App\Models\WorkReport;
use App\Services\Telegram\TelegramClient;

/**
 * Итоговый отчёт о ремонте (ТЗ п.39-45, 56-58): что сделано → цена работ → цена
 * запчастей клиенту → себестоимость запчастей → выплата мастеру → медиа →
 * подтверждение. Только подтверждение переводит заказ в COMPLETED — WorkReport
 * до этого момента не существует (черновик целиком в PendingInput).
 *
 * Медиа собираются на стадию AFTER до создания WorkReport (его ещё нет — id
 * неоткуда взять), а привязываются к нему уже в confirm(), см. там.
 */
class WorkReportFlow
{
    private const KIND = 'work_report';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
        private readonly MasterOrderGuard $guard,
        private readonly MediaCollector $mediaCollector,
    ) {}

    public function start(User $master, int $chatId, int $orderNumber): void
    {
        if (! $this->guard->resolve($master, $orderNumber, OrderStatus::IN_PROGRESS, $chatId)) {
            return;
        }

        PendingInput::query()->updateOrCreate(
            ['user_id' => $master->id],
            [
                'kind' => self::KIND,
                'payload' => ['order_number' => $orderNumber, 'step' => 'description'],
                'expires_at' => now()->addMinutes(30),
            ]
        );

        $this->telegram->sendMessage($chatId, 'Опишите, что было сделано:');
    }

    public function handle(User $master, PendingInput $pending, int $chatId, ?string $text, ?string $callback): void
    {
        if ($callback === 'edit') {
            $this->restart($pending, $chatId);

            return;
        }

        if ($callback === 'confirm') {
            $this->confirm($master, $chatId, $pending);

            return;
        }

        $text = $text !== null ? trim($text) : null;

        if ($callback === 'media_done') {
            $this->showSummary($pending, $chatId);

            return;
        }

        match ($pending->get('step')) {
            'description' => $this->handleDescription($pending, $chatId, $text),
            'labor_price' => $this->handleLaborPrice($pending, $chatId, $text),
            'parts_sell_price' => $this->handlePartsSellPrice($pending, $chatId, $text),
            'parts_cost' => $this->handlePartsCost($pending, $chatId, $text),
            'master_payout' => $this->handleMasterPayout($pending, $chatId, $text),
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $photo
     * @param  array<string, mixed>|null  $video
     */
    public function handleMedia(User $master, PendingInput $pending, int $chatId, ?array $photo, ?array $video): void
    {
        if ($pending->get('step') !== 'media') {
            $this->telegram->sendMessage($chatId, 'Сначала заполни отчёт.');

            return;
        }

        $orderNumber = (int) $pending->get('order_number');
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::IN_PROGRESS, $chatId);

        if (! $order) {
            return;
        }

        $this->mediaCollector->handleIncoming($order, $master, MediaStage::AFTER, $chatId, $photo, $video);
    }

    private function handleDescription(PendingInput $pending, int $chatId, ?string $text): void
    {
        if (! $text) {
            $this->telegram->sendMessage($chatId, 'Опишите, что было сделано:');

            return;
        }

        $pending->put('work_description', $text);
        $pending->put('step', 'labor_price');
        $pending->save();

        $order = Order::query()->where('number', $pending->get('order_number'))->first();
        $hint = $order?->labor_price ? " (согласовано с клиентом: {$order->labor_price} ₽)" : '';
        $this->telegram->sendMessage($chatId, "Стоимость работ для клиента, ₽{$hint}:");
    }

    private function handleLaborPrice(PendingInput $pending, int $chatId, ?string $text): void
    {
        $value = $this->parsePrice($text);

        if ($value === null) {
            $this->telegram->sendMessage($chatId, 'Введи число рублей:');

            return;
        }

        $pending->put('labor_price', $value);
        $pending->put('step', 'parts_sell_price');
        $pending->save();

        $order = Order::query()->where('number', $pending->get('order_number'))->first();
        $hint = $order?->parts_sell_price !== null ? " (согласовано с клиентом: {$order->parts_sell_price} ₽)" : '';
        $this->telegram->sendMessage($chatId, "Стоимость запчастей для клиента, ₽{$hint}:");
    }

    private function handlePartsSellPrice(PendingInput $pending, int $chatId, ?string $text): void
    {
        $value = $this->parsePrice($text);

        if ($value === null) {
            $this->telegram->sendMessage($chatId, 'Введи число рублей, 0 если запчастей не было:');

            return;
        }

        $pending->put('parts_sell_price', $value);
        $pending->put('step', 'parts_cost');
        $pending->save();

        $this->telegram->sendMessage($chatId, 'Себестоимость запчастей, ₽ (для расчёта маржи, 0 если не было):');
    }

    private function handlePartsCost(PendingInput $pending, int $chatId, ?string $text): void
    {
        $value = $this->parsePrice($text);

        if ($value === null) {
            $this->telegram->sendMessage($chatId, 'Введи число рублей, 0 если запчастей не было:');

            return;
        }

        $pending->put('parts_cost', $value);
        $pending->put('step', 'master_payout');
        $pending->save();

        $this->telegram->sendMessage($chatId, 'Выплата мастеру, ₽:');
    }

    private function handleMasterPayout(PendingInput $pending, int $chatId, ?string $text): void
    {
        $value = $this->parsePrice($text);

        if ($value === null) {
            $this->telegram->sendMessage($chatId, 'Введи число рублей:');

            return;
        }

        $pending->put('master_payout', $value);
        $pending->put('step', 'media');
        $pending->save();

        $this->telegram->sendMessage(
            $chatId,
            'Добавьте фото и видео ремонта (общий вид техники, неисправный узел, установленную деталь, результат — необязательно). Когда закончите — нажмите «Медиа закончены».',
            ['inline_keyboard' => [[['text' => 'Медиа закончены', 'callback_data' => 'work_report:media_done']]]]
        );
    }

    private function showSummary(PendingInput $pending, int $chatId): void
    {
        $laborPrice = (int) $pending->get('labor_price');
        $partsSellPrice = (int) $pending->get('parts_sell_price');
        $finalPrice = $laborPrice + $partsSellPrice;

        $order = Order::query()->where('number', $pending->get('order_number'))->first();
        $counts = $order ? $this->mediaCollector->counts($order) : ['photos' => 0, 'videos' => 0];

        $lines = [
            "Заявка {$order?->code()}",
            "Что сделано: {$pending->get('work_description')}",
            "Работы: {$laborPrice} ₽",
            "Запчасти: {$partsSellPrice} ₽",
            "Итого клиенту: {$finalPrice} ₽",
            "Выплата мастеру: {$pending->get('master_payout')} ₽",
            "Фото: {$counts['photos']}",
            "Видео: {$counts['videos']}",
        ];

        if ($order?->estimated_price !== null && $order->estimated_price !== $finalPrice) {
            $lines[] = "⚠️ Отличается от согласованного с клиентом ({$order->estimated_price} ₽)";
        }

        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'inline_keyboard' => [[
                ['text' => '✅ Подтвердить завершение', 'callback_data' => 'work_report:confirm'],
                ['text' => '✏️ Изменить', 'callback_data' => 'work_report:edit'],
            ]],
        ]);
    }

    private function restart(PendingInput $pending, int $chatId): void
    {
        $pending->payload = ['order_number' => $pending->get('order_number'), 'step' => 'description'];
        $pending->save();

        $this->telegram->sendMessage($chatId, 'Опишите, что было сделано:');
    }

    private function confirm(User $master, int $chatId, PendingInput $pending): void
    {
        $orderNumber = (int) $pending->get('order_number');
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::IN_PROGRESS, $chatId);

        $laborPrice = (int) $pending->get('labor_price');
        $partsSellPrice = (int) $pending->get('parts_sell_price');
        $partsCost = (int) $pending->get('parts_cost');
        $masterPayout = (int) $pending->get('master_payout');
        $description = $pending->get('work_description');
        $finalPrice = $laborPrice + $partsSellPrice;

        $pending->delete();

        if (! $order) {
            return;
        }

        $currentVisit = $order->visits()->where('status', 'SCHEDULED')->latest('visit_number')->first();

        try {
            $order = $this->statusMachine->transition(
                $order,
                OrderStatus::COMPLETED,
                $master,
                attributes: [
                    'final_price' => $finalPrice,
                    'parts_sell_price' => $partsSellPrice,
                    'parts_cost' => $partsCost,
                    'master_payout' => $masterPayout,
                    'master_comment' => $description,
                    'completed_at' => now(),
                ],
            );
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $currentVisit?->update(['status' => 'COMPLETED', 'completed_at' => now()]);

        $report = WorkReport::query()->create([
            'order_id' => $order->id,
            'visit_id' => $currentVisit?->id,
            'master_id' => $master->master?->id,
            'failure_reason' => $order->failure_reason,
            'work_description' => $description,
            'labor_price' => $laborPrice,
            'parts_sell_price' => $partsSellPrice,
            'parts_cost' => $partsCost,
            'final_price' => $finalPrice,
            'master_payout' => $masterPayout,
            'confirmed_at' => now(),
        ]);

        // Медиа собирались до создания отчёта (id ещё не было) — привязываем задним числом.
        OrderMedia::query()
            ->where('order_id', $order->id)
            ->where('stage', MediaStage::AFTER->value)
            ->whereNull('work_report_id')
            ->update(['work_report_id' => $report->id]);

        $order->load('master');

        $this->telegram->sendMessage(
            $chatId,
            "Заявка {$order->code()} завершена. Итого: {$finalPrice} ₽. Твоя выплата: {$masterPayout} ₽."
        );

        $this->notifier->orderCompleted($order, $report);
    }

    private function parsePrice(?string $text): ?int
    {
        if ($text === null || ! ctype_digit($text)) {
            return null;
        }

        return (int) $text;
    }
}
