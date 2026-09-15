<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Crm\CrmAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ п.85.1: Telegram/бизнес-логика не знают про CRM напрямую — они только
 * складывают заказ в очередь, этот job снимает его оттуда и передаёт в
 * CrmAdapter. ТЗ п.87: если CRM недоступна — заказ продолжает работать,
 * синк уходит в очередь и ретраится автоматически. $tries/backoff() — это
 * и есть тот самый retry queue, отдельный механизм городить не нужно.
 */
class SyncOrderToCrm implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly Order $order) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600, 1800];
    }

    public function handle(CrmAdapter $adapter): void
    {
        $crmId = $adapter->syncOrder($this->order);

        if ($crmId !== null && $crmId !== $this->order->crm_id) {
            $this->order->update(['crm_id' => $crmId]);
        }
    }

    /**
     * ТЗ п.87: "CRM не является single point of failure" держится безусловно,
     * не только пока очередь на Redis отвечает — с QUEUE_CONNECTION=sync (тесты)
     * или при временной недоступности самой очереди dispatch() может бросить
     * исключение прямо в вызывающем коде. Единая точка вызова вместо try/catch
     * на каждом из трёх мест, откуда синк запускается (OrderStatusMachine,
     * OrderController::store/update).
     */
    public static function dispatchSafely(Order $order): void
    {
        try {
            self::dispatch($order);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Все попытки исчерпаны — не бросаем дальше (некому это показать), просто
     * оставляем след в логе/Sentry. Order к этому моменту уже давно отработал
     * свою часть в Telegram независимо от исхода синка.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('crm.sync.failed', [
            'order_id' => $this->order->id,
            'order_number' => $this->order->number,
            'error' => $exception->getMessage(),
        ]);

        report($exception);
    }
}
