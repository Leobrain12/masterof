<?php

namespace App\Services\Orders;

use App\Enums\MediaType;
use App\Models\Order;

/**
 * Единый формат карточки заказа для списков (Figma-спек: #id + статус, дата/слот,
 * техника+бренд, проблема в одну строку, клиент, адрес, мастер).
 */
class OrderCardFormatter
{
    public function format(Order $order): string
    {
        $lines = [
            "{$order->code()} · {$order->status->label()}",
            $order->visit_date->translatedFormat('d.m.Y')." · {$order->time_slot_label}",
            $order->applianceType->name.($order->brand ? " {$order->brand->name}" : ''),
            $order->symptom,
            $order->customer_name,
            $order->address,
            'Мастер: '.($order->master->name ?? '—'),
        ];

        if ($order->warranty_parent_order_id && $order->warrantyParent) {
            $lines[] = "🔧 Гарантия по {$order->warrantyParent->code()}";
        }

        $photos = $order->media()->where('media_type', MediaType::PHOTO->value)->count();
        $videos = $order->media()->where('media_type', MediaType::VIDEO->value)->count();

        if ($photos || $videos) {
            $lines[] = "📷 Фото: {$photos} · Видео: {$videos}";
        }

        return implode("\n", $lines);
    }
}
