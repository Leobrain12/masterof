<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PendingInput;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Экраны "Нераспределённые" (ТЗ п.71) и "Активные заказы" (ТЗ п.А2, F2) для ADMIN.
 * Каждый заказ — отдельным сообщением, чтобы у своей карточки была своя кнопка
 * (Telegram не даёt разные reply_markup на "под-блоки" одного сообщения).
 */
class OrderListScreens
{
    private const LIMIT = 15;

    private const SEARCH_LIMIT = 10;

    private const SEARCH_KIND = 'order_search';

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

    /**
     * Заявки с визитом сегодня, независимо от статуса — расписание на день,
     * не срез по стадии заказа (для этого уже есть «Активные»).
     */
    public function today(int $chatId): void
    {
        $orders = Order::query()
            ->with(['applianceType', 'brand', 'master', 'warrantyParent'])
            // whereDate(), не where() — visit_date хранится как полный datetime
            // (формат даты SQLite-грамматики по умолчанию), точное строковое
            // сравнение с "Y-m-d" никогда бы не совпало.
            ->whereDate('visit_date', now()->toDateString())
            ->orderBy('time_slot_label')
            ->limit(self::LIMIT)
            ->get();

        if ($orders->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'На сегодня заявок нет.');

            return;
        }

        foreach ($orders as $order) {
            $this->telegram->sendMessage($chatId, $this->formatter->format($order));
        }
    }

    public function promptSearch(User $admin, int $chatId): void
    {
        PendingInput::query()->updateOrCreate(
            ['user_id' => $admin->id],
            ['kind' => self::SEARCH_KIND, 'payload' => [], 'expires_at' => now()->addMinutes(10)]
        );

        $this->telegram->sendMessage($chatId, 'Номер заявки (#1234) или телефон клиента:');
    }

    /**
     * Формат ввода в ТЗ не описан буквально — эвристика: короткая (≤6 цифр)
     * последовательность цифр это номер заявки, всё остальное — телефон
     * (сравнивается по подстроке цифр, без учёта формата +7/8/пробелов).
     */
    public function handleSearchText(User $admin, PendingInput $pending, int $chatId, string $text): void
    {
        $pending->delete();

        $digits = preg_replace('/\D+/', '', $text) ?? '';

        $query = Order::query()->with(['applianceType', 'brand', 'master', 'warrantyParent']);

        $query = $digits !== '' && strlen($digits) <= 6
            ? $query->where('number', (int) $digits)
            : $query->where('customer_phone', 'like', '%'.$digits.'%');

        $orders = $query->orderByDesc('created_at')->limit(self::SEARCH_LIMIT)->get();

        if ($orders->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Ничего не найдено.');

            return;
        }

        foreach ($orders as $order) {
            $this->telegram->sendMessage($chatId, $this->formatter->format($order));
        }
    }
}
