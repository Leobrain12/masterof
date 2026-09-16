<?php

namespace App\Services\Orders;

use App\Models\Master;
use App\Services\Telegram\TelegramClient;

/**
 * Экран «Мастера» (ADMIN/SUPERADMIN) — плоский список, не по сообщению на
 * мастера: тут нет per-item действия, в отличие от OrderListScreens.
 */
class MasterRosterScreen
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function list(int $chatId): void
    {
        $masters = Master::query()
            ->with(['applianceTypes', 'brands', 'geoZones'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        if ($masters->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Мастеров нет.');

            return;
        }

        $lines = [];

        foreach ($masters as $master) {
            $status = $master->is_active ? $master->status->label() : 'отключён';
            $specialization = $master->applianceTypes->pluck('name')->join(', ') ?: '—';
            $brands = $master->brands->pluck('name')->join(', ') ?: '—';
            $zones = $master->geoZones->pluck('name')->join(', ') ?: '—';

            $lines[] = "{$master->name} — {$master->phone} ({$status})\n".
                "Техника: {$specialization} · Бренды: {$brands} · Зоны: {$zones}";
        }

        $this->telegram->sendMessage($chatId, implode("\n\n", $lines));
    }
}
