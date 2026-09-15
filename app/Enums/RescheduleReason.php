<?php

namespace App\Enums;

/**
 * Причина переноса заказа (ТЗ п.35).
 */
enum RescheduleReason: string
{
    case CUSTOMER_REQUEST = 'CUSTOMER_REQUEST';
    case MASTER_REQUEST = 'MASTER_REQUEST';
    case NO_PART = 'NO_PART';
    case MASTER_LATE = 'MASTER_LATE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER_REQUEST => 'По просьбе клиента',
            self::MASTER_REQUEST => 'По просьбе мастера',
            self::NO_PART => 'Нет детали',
            self::MASTER_LATE => 'Не успевает мастер',
            self::OTHER => 'Другое',
        };
    }
}
