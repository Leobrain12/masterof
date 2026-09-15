<?php

namespace App\Enums;

/**
 * Результат диагностики (ТЗ п.27). Не хранится отдельным полем — только определяет,
 * в какой статус уходит заказ дальше (см. App\Services\Orders\DiagnosisFlow).
 */
enum DiagnosisOutcome: string
{
    case REPAIRABLE = 'REPAIRABLE';
    case NEED_PART = 'NEED_PART';
    case UNREPAIRABLE = 'UNREPAIRABLE';
    case CUSTOMER_DECLINED = 'CUSTOMER_DECLINED';

    public function label(): string
    {
        return match ($this) {
            self::REPAIRABLE => 'Можно ремонтировать',
            self::NEED_PART => 'Нужна деталь',
            self::UNREPAIRABLE => 'Ремонт нецелесообразен',
            self::CUSTOMER_DECLINED => 'Клиент отказался',
        };
    }

    public function targetStatus(): OrderStatus
    {
        return match ($this) {
            self::REPAIRABLE => OrderStatus::PRICE_APPROVAL,
            self::NEED_PART => OrderStatus::WAITING_PART,
            self::UNREPAIRABLE => OrderStatus::UNREPAIRABLE,
            self::CUSTOMER_DECLINED => OrderStatus::CUSTOMER_CANCELLED,
        };
    }
}
