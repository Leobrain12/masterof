<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Master;
use App\Models\Order;
use App\Models\OrderVisit;
use App\Models\PendingInput;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Str;

/**
 * Гарантийное обращение (ТЗ п.64-65): администратор оформляет новый визит по
 * уже выполненному заказу. Не правка исходного заказа — отдельная строка в
 * orders с warranty_parent_order_id на исходный (ТЗ п.64.2), стартовый статус
 * WARRANTY_RETURN сразу переходит в ASSIGNED, потому что мастер и новый слот —
 * обязательные поля самого обращения (ТЗ п.64.1), тот же паттерн, что и в
 * OrderDraftFlow при создании обычной заявки. Дальше — тот же полевой цикл,
 * что у любого заказа (OrderStatusMachine один на все заказы).
 *
 * Гарантийный заказ намеренно НЕ считается обычным коммерческим в статистике
 * (ТЗ п.65) — см. OrderStatsCalculator/MasterStatsCalculator.
 */
class WarrantyReturnFlow
{
    private const KIND = 'warranty_return';

    private const PREFIX = 'warranty';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
        private readonly MasterMatcher $matcher,
        private readonly DateSlotPicker $picker,
    ) {}

    public function start(User $admin, int $chatId, int $orderNumber): void
    {
        $order = $this->findWarrantableOrder($orderNumber, $chatId);

        if (! $order) {
            return;
        }

        PendingInput::query()->updateOrCreate(
            ['user_id' => $admin->id],
            [
                'kind' => self::KIND,
                'payload' => ['order_number' => $orderNumber, 'step' => 'symptom'],
                'expires_at' => now()->addMinutes(20),
            ]
        );

        $this->telegram->sendMessage($chatId, "Гарантийное обращение по {$order->code()}.\nОпишите проблему:");
    }

    public function handle(User $admin, PendingInput $pending, int $chatId, ?string $text, ?string $callback): void
    {
        $skip = $callback === 'skip';
        $text = $text !== null ? trim($text) : null;

        match ($pending->get('step')) {
            'symptom' => $this->handleSymptom($pending, $chatId, $text),
            'comment' => $this->handleComment($pending, $chatId, $text, $skip),
            'date' => $this->handleDate($pending, $chatId, $callback),
            'date_custom' => $this->handleDateCustom($pending, $chatId, $text),
            'slot' => $this->handleSlot($pending, $chatId, $callback),
            'slot_custom' => $this->handleSlotCustom($pending, $chatId, $text),
            'master' => $this->handleMaster($admin, $pending, $chatId, $callback),
            default => $pending->delete(),
        };
    }

    private function handleSymptom(PendingInput $pending, int $chatId, ?string $text): void
    {
        if (! $text) {
            $this->telegram->sendMessage($chatId, 'Опишите проблему:');

            return;
        }

        $pending->put('symptom', $text);
        $pending->put('step', 'comment');
        $pending->save();

        $this->telegram->sendMessage($chatId, 'Комментарий (или пропусти):', $this->skipKeyboard());
    }

    private function handleComment(PendingInput $pending, int $chatId, ?string $text, bool $skip): void
    {
        if (! $skip && ! $text) {
            $this->telegram->sendMessage($chatId, 'Комментарий (или пропусти):', $this->skipKeyboard());

            return;
        }

        $pending->put('comment', $skip ? null : $text);
        $pending->put('step', 'date');
        $pending->save();

        $this->picker->promptDate($chatId, self::PREFIX);
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

        $this->promptMaster($pending, $chatId, $slot->label);
    }

    private function handleSlotCustom(PendingInput $pending, int $chatId, ?string $text): void
    {
        if (! $text || trim($text) === '') {
            $this->picker->promptCustomSlot($chatId);

            return;
        }

        $this->promptMaster($pending, $chatId, trim($text));
    }

    private function promptMaster(PendingInput $pending, int $chatId, string $timeSlotLabel): void
    {
        $pending->put('time_slot_label', $timeSlotLabel);
        $pending->put('step', 'master');
        $pending->save();

        $orderNumber = (int) $pending->get('order_number');
        $parent = Order::query()->where('number', $orderNumber)->first();

        if (! $parent) {
            $pending->delete();
            $this->telegram->sendMessage($chatId, "Заявка #{$orderNumber} больше не найдена.");

            return;
        }

        $result = $this->matcher->forOrder($parent->appliance_type_id, $parent->brand_id, $parent->geo_zone_id);
        $masters = $result['masters'];

        if ($masters->isEmpty()) {
            $pending->delete();
            $this->telegram->sendMessage($chatId, 'Нет ни одного активного мастера в системе.');

            return;
        }

        $lines = $result['exact']
            ? ['Подходящие мастера:']
            : ['Точных совпадений нет, показаны все доступные:'];

        foreach ($masters as $m) {
            $lines[] = "{$m->name} — ".($m->brands->pluck('name')->join(', ') ?: '—').' · '.($m->geoZones->pluck('name')->join(', ') ?: '—');
        }

        $buttons = $masters
            ->map(fn (Master $m) => [['text' => $m->name, 'callback_data' => "warranty:master:{$m->id}"]])
            ->all();

        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $buttons]);
    }

    private function handleMaster(User $admin, PendingInput $pending, int $chatId, ?string $callback): void
    {
        $masterId = $callback ? Str::after($callback, 'master:') : null;
        $master = $masterId ? Master::query()->find($masterId) : null;

        if (! $master) {
            $this->telegram->sendMessage($chatId, 'Выбери мастера из списка.');

            return;
        }

        $this->apply($admin, $pending, $chatId, $master);
    }

    private function apply(User $admin, PendingInput $pending, int $chatId, Master $master): void
    {
        $orderNumber = (int) $pending->get('order_number');
        $symptom = $pending->get('symptom');
        $comment = $pending->get('comment');
        $visitDate = $pending->get('visit_date');
        $timeSlotLabel = $pending->get('time_slot_label');
        $pending->delete();

        $parent = $this->findWarrantableOrder($orderNumber, $chatId);

        if (! $parent) {
            return;
        }

        $warranty = Order::query()->create([
            'warranty_parent_order_id' => $parent->id,
            'source' => 'warranty',
            'customer_name' => $parent->customer_name,
            'customer_phone' => $parent->customer_phone,
            'appliance_type_id' => $parent->appliance_type_id,
            'brand_id' => $parent->brand_id,
            'model' => $parent->model,
            'symptom' => $symptom,
            'admin_comment' => $comment,
            'address' => $parent->address,
            'geo_zone_id' => $parent->geo_zone_id,
            'visit_date' => $visitDate,
            'time_slot_label' => $timeSlotLabel,
            'status' => OrderStatus::WARRANTY_RETURN,
            'created_by' => $admin->id,
        ]);

        try {
            $warranty = $this->statusMachine->transition(
                $warranty,
                OrderStatus::ASSIGNED,
                $admin,
                attributes: ['master_id' => $master->id],
            );
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, 'Не получилось назначить мастера. Попробуй ещё раз.');

            return;
        }

        OrderVisit::query()->create([
            'order_id' => $warranty->id,
            'visit_number' => 1,
            'visit_date' => $warranty->visit_date,
            'time_slot_label' => $warranty->time_slot_label,
            'master_id' => $warranty->master_id,
            'status' => 'SCHEDULED',
            'reason' => "Гарантия по {$parent->code()}",
        ]);

        $warranty->load('master.user');

        $this->telegram->sendMessage(
            $chatId,
            "Гарантийное обращение {$warranty->code()} создано по {$parent->code()}, назначено {$master->name}."
        );

        $this->notifier->warrantyCreated($warranty, $parent);
        $this->notifier->assignedToMaster($warranty);
    }

    private function findWarrantableOrder(int $orderNumber, int $chatId): ?Order
    {
        $order = Order::query()->where('number', $orderNumber)->first();

        if (! $order) {
            $this->telegram->sendMessage($chatId, "Заявка #{$orderNumber} не найдена.");

            return null;
        }

        if (! in_array($order->status, [OrderStatus::COMPLETED, OrderStatus::PAID], true)) {
            $this->telegram->sendMessage(
                $chatId,
                "Заявка {$order->code()} ещё не завершена — гарантийное обращение недоступно."
            );

            return null;
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function skipKeyboard(): array
    {
        return ['inline_keyboard' => [[['text' => 'Пропустить', 'callback_data' => 'warranty:skip']]]];
    }
}
