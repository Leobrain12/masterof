<?php

namespace App\Enums;

/**
 * Полный набор статусов заказа (ТЗ п.14, 14.1). Не каждый статус уже достижим —
 * переходы, которых бот пока не запускает, появятся вместе со своими фазами
 * (см. App\Services\Orders\OrderStatusMachine).
 */
enum OrderStatus: string
{
    case NEW = 'NEW';
    case ASSIGNED = 'ASSIGNED';
    case ACCEPTED = 'ACCEPTED';
    case ON_THE_WAY = 'ON_THE_WAY';
    case ARRIVED = 'ARRIVED';
    case DIAGNOSTICS = 'DIAGNOSTICS';
    case PRICE_APPROVAL = 'PRICE_APPROVAL';
    case IN_PROGRESS = 'IN_PROGRESS';
    case WAITING_PART = 'WAITING_PART';
    case COMPLETED = 'COMPLETED';
    case PAID = 'PAID';
    case MASTER_DECLINED = 'MASTER_DECLINED';
    case RESCHEDULED = 'RESCHEDULED';
    case CUSTOMER_CANCELLED = 'CUSTOMER_CANCELLED';
    case NO_CONTACT = 'NO_CONTACT';
    case UNREPAIRABLE = 'UNREPAIRABLE';
    case CANCELLED = 'CANCELLED';
    case WARRANTY_RETURN = 'WARRANTY_RETURN';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Новый',
            self::ASSIGNED => 'Назначен',
            self::ACCEPTED => 'Принят',
            self::ON_THE_WAY => 'В пути',
            self::ARRIVED => 'На месте',
            self::DIAGNOSTICS => 'Диагностика',
            self::PRICE_APPROVAL => 'Согласование цены',
            self::IN_PROGRESS => 'В работе',
            self::WAITING_PART => 'Нужна деталь',
            self::COMPLETED => 'Выполнен',
            self::PAID => 'Оплачен',
            self::MASTER_DECLINED => 'Отказ мастера',
            self::RESCHEDULED => 'Перенесён',
            self::CUSTOMER_CANCELLED => 'Клиент отказался',
            self::NO_CONTACT => 'Недозвон',
            self::UNREPAIRABLE => 'Не ремонтируется',
            self::CANCELLED => 'Отменён',
            self::WARRANTY_RETURN => 'Гарантия',
        };
    }
}
