<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Telegram\TelegramClient;

/**
 * Экраны "Нераспределённые" (ТЗ п.71) и "Активные заказы" (ТЗ п.А2, F2) для ADMIN.
 * Каждый заказ — отдельным сообщением, чтобы у своей карточки была своя кнопка
 * (Telegram не даёt разные reply_markup на "под-блоки" одного сообщения).
 */
class OrderListScreens
{
    private const LIMIT = 15;

    /**
     * "Активные" — всё, что не в "Нераспределённые" (NEW/MASTER_DECLINED, ждут
     * назначения) и не закрыто окончательно. До фазы 06 список ограничивался
     * ASSIGNED/ACCEPTED — оставшимся от фазы 01, когда это были единственные
     * достижимые статусы; с тех пор заказ бо́льшую часть жизни проводит в
     * других статусах, и админ их не видел (см. vault/Открытые вопросы.md).
     * Не allow-list, а исключение терминальных — так список не устаревает
     * снова, когда появится очередной статус.
     *
     * @var list<string>
     */
    private const CLOSED_OR_UNASSIGNED_STATUSES = [
        'NEW', 'MASTER_DECLINED',
        'PAID', 'CANCELLED', 'CUSTOMER_CANCELLED', 'UNREPAIRABLE', 'NO_CONTACT', 'WARRANTY_RETURN',
    ];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderCardFormatter $formatter,
    ) {}

    public function unassigned(int $chatId): void
    {
        $orders = Order::query()
            ->with(['applianceType', 'brand', 'master', 'warrantyParent'])
            ->whereIn('status', [OrderStatus::NEW->value, OrderStatus::MASTER_DECLINED->value])
            ->orderBy('created_at')
            ->limit(self::LIMIT)
            ->get();

        if ($orders->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Нераспределённых заявок нет.');

            return;
        }

        foreach ($orders as $order) {
            $buttons = [[['text' => '👤 Назначить мастера', 'callback_data' => "order:reassign:{$order->number}"]]];

            if ($order->media()->exists()) {
                $buttons[] = [['text' => '🖼 Медиа', 'callback_data' => "order:view_media:{$order->number}"]];
            }

            $this->telegram->sendMessage($chatId, $this->formatter->format($order), ['inline_keyboard' => $buttons]);
        }
    }

    public function active(int $chatId): void
    {
        $orders = Order::query()
            ->with(['applianceType', 'brand', 'master', 'warrantyParent'])
            ->whereNotIn('status', self::CLOSED_OR_UNASSIGNED_STATUSES)
            ->orderBy('visit_date')
            ->limit(self::LIMIT)
            ->get();

        if ($orders->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Активных заказов нет.');

            return;
        }

        foreach ($orders as $order) {
            $replyMarkup = $order->media()->exists()
                ? ['inline_keyboard' => [[['text' => '🖼 Медиа', 'callback_data' => "order:view_media:{$order->number}"]]]]
                : null;

            $this->telegram->sendMessage($chatId, $this->formatter->format($order), $replyMarkup);
        }
    }
}
