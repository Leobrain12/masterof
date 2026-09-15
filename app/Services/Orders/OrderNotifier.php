<?php

namespace App\Services\Orders;

use App\Enums\ContactAttemptResult;
use App\Enums\OrderDeclineReason;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Models\WorkReport;
use App\Services\Telegram\TelegramClient;

/**
 * Все сообщения, которые заказ рассылает сам по себе на каждом шаге (ТЗ п.19-22, 81-82).
 * Админам заказ шлётся широковещательно — ролево это "администратор получает",
 * а не конкретный один человек (ТЗ не разделяет уведомления по конкретному админу).
 */
class OrderNotifier
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function orderCreated(Order $order, User $creator): void
    {
        $this->telegram->sendMessage(
            $creator->telegram_user_id,
            "Заявка {$order->code()} создана и назначена мастеру {$order->master->name}."
        );
    }

    public function assignedToMaster(Order $order): void
    {
        $master = $order->master;

        $this->telegram->sendMessage(
            $master->user->telegram_user_id,
            $this->orderCard($order),
            [
                'inline_keyboard' => [[
                    ['text' => '✅ Принять', 'callback_data' => "order:accept:{$order->number}"],
                    ['text' => '❌ Отказаться', 'callback_data' => "order:decline:{$order->number}"],
                ]],
            ]
        );
    }

    public function masterAccepted(Order $order): void
    {
        $this->broadcastToAdmins("Мастер {$order->master->name} принял заявку {$order->code()}.");
    }

    public function masterDeclined(Order $order, OrderDeclineReason $reason, ?string $comment): void
    {
        $text = "Мастер {$order->master?->name} отказался от заявки {$order->code()}.\n".
            "Причина: {$reason->label()}".
            ($comment ? "\n{$comment}" : '');

        $this->broadcastToAdmins($text, [
            'inline_keyboard' => [[
                ['text' => '👤 Назначить другого мастера', 'callback_data' => "order:reassign:{$order->number}"],
            ]],
        ]);
    }

    public function reassignedByAdmin(Order $order, User $admin): void
    {
        $this->telegram->sendMessage(
            $admin->telegram_user_id,
            "Заявка {$order->code()} переназначена мастеру {$order->master->name}."
        );
    }

    public function masterDeparted(Order $order): void
    {
        $this->broadcastToAdmins("Мастер {$order->master->name} выехал на заявку {$order->code()}.");
    }

    public function masterArrived(Order $order): void
    {
        $this->broadcastToAdmins("Мастер {$order->master->name} на месте по заявке {$order->code()}.");
    }

    public function diagnosticsStarted(Order $order): void
    {
        $this->broadcastToAdmins("Мастер {$order->master->name} начал диагностику по заявке {$order->code()}.");
    }

    public function diagnosisResult(Order $order, string $outcomeLabel): void
    {
        $this->broadcastToAdmins("Диагностика по заявке {$order->code()}: {$outcomeLabel}.");
    }

    public function workStarted(Order $order): void
    {
        $this->broadcastToAdmins(
            "Клиент согласовал ремонт по заявке {$order->code()} на {$order->estimated_price} ₽. Мастер приступил к работе."
        );
    }

    public function customerCancelled(Order $order, string $reason): void
    {
        $this->broadcastToAdmins("Клиент отказался по заявке {$order->code()}.\nПричина: {$reason}");
    }

    public function contactAttemptRecorded(Order $order, ContactAttemptResult $result, int $attemptsCount, int $threshold): void
    {
        $text = "Мастер {$order->master->name} не дозвонился по заявке {$order->code()} ({$result->label()}). Попыток: {$attemptsCount}.";

        $replyMarkup = $attemptsCount >= $threshold
            ? ['inline_keyboard' => [[['text' => '📵 Перевести в недозвон', 'callback_data' => "order:no_contact_escalate:{$order->number}"]]]]
            : null;

        $this->broadcastToAdmins($text, $replyMarkup);
    }

    public function noContactEscalated(Order $order): void
    {
        $this->broadcastToAdmins("Заявка {$order->code()} переведена в статус «Недозвон».");
    }

    public function rescheduled(Order $order, string $oldDate, string $oldSlot, string $reasonLabel): void
    {
        $text = "Заявка {$order->code()} перенесена.\n".
            "Было: {$oldDate} {$oldSlot}\n".
            "Стало: {$order->visit_date->translatedFormat('d.m.Y')} {$order->time_slot_label}\n".
            "Причина: {$reasonLabel}";

        $this->broadcastToAdmins($text);
    }

    public function partRequested(Order $order): void
    {
        $lines = [
            "По заявке {$order->code()} требуется запчасть.",
            "Мастер: {$order->master->name}",
            "Деталь: {$order->part_name}",
        ];

        if ($order->part_article) {
            $lines[] = "Артикул: {$order->part_article}";
        }

        if ($order->part_purchase_price !== null) {
            $lines[] = "Ориентировочная цена: {$order->part_purchase_price} ₽";
        }

        if ($order->part_comment) {
            $lines[] = "Комментарий: {$order->part_comment}";
        }

        $this->broadcastToAdmins(implode("\n", $lines));
    }

    public function visitScheduled(Order $order, int $visitNumber): void
    {
        $this->broadcastToAdmins(
            "Заявка {$order->code()}: визит #{$visitNumber} запланирован на ".
            "{$order->visit_date->translatedFormat('d.m.Y')} {$order->time_slot_label}. Мастер: {$order->master->name}."
        );
    }

    public function orderCompleted(Order $order, WorkReport $report): void
    {
        $lines = [
            "Заявка {$order->code()} выполнена.",
            "Мастер: {$order->master->name}",
            "Работы: {$report->work_description}",
            "Итого клиенту: {$order->final_price} ₽",
        ];

        if ($order->estimated_price !== null && $order->estimated_price !== $order->final_price) {
            $lines[] = "⚠️ Отличается от согласованного с клиентом ({$order->estimated_price} ₽)";
        }

        $this->broadcastToAdmins(implode("\n", $lines), [
            'inline_keyboard' => [[
                ['text' => '💰 Оплачено полностью', 'callback_data' => "order:pay_full:{$order->number}"],
                ['text' => '💵 Частично', 'callback_data' => "order:pay_partial:{$order->number}"],
            ], [
                ['text' => '❌ Не оплачено', 'callback_data' => "order:pay_none:{$order->number}"],
            ]],
        ]);
    }

    public function paymentRecorded(Order $order, string $summary): void
    {
        $this->broadcastToAdmins("Оплата по заявке {$order->code()}: {$summary}.");
    }

    private function orderCard(Order $order): string
    {
        $lines = [
            "Новая заявка {$order->code()}",
            "Дата: {$order->visit_date->translatedFormat('d.m.Y')}",
            "Время: {$order->time_slot_label}",
            "Техника: {$order->applianceType->name}".($order->brand ? " {$order->brand->name}" : ''),
        ];

        if ($order->model) {
            $lines[] = "Модель: {$order->model}";
        }

        $lines[] = "Проблема: {$order->symptom}";
        $lines[] = "Адрес: {$order->address}";
        $lines[] = "Клиент: {$order->customer_name}";
        $lines[] = "Телефон: {$order->customer_phone}";

        if ($order->admin_comment) {
            $lines[] = "Комментарий администратора: {$order->admin_comment}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    private function broadcastToAdmins(string $text, ?array $replyMarkup = null): void
    {
        User::query()
            ->whereIn('role', [UserRole::SUPERADMIN->value, UserRole::ADMIN->value])
            ->where('is_active', true)
            ->get()
            ->each(fn (User $admin) => $this->telegram->sendMessage($admin->telegram_user_id, $text, $replyMarkup));
    }
}
