<?php

namespace App\Enums;

/**
 * Причина отказа клиента после согласования цены (ТЗ п.31) — отличается от общих
 * причин отказа мастера (OrderDeclineReason) и от отказа клиента на диагностике,
 * для которого ТЗ отдельного списка причин не даёт.
 */
enum PriceDeclineReason: string
{
    case EXPENSIVE = 'EXPENSIVE';
    case CHANGED_MIND = 'CHANGED_MIND';
    case BUYING_NEW = 'BUYING_NEW';
    case CALLED_ANOTHER_MASTER = 'CALLED_ANOTHER_MASTER';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::EXPENSIVE => 'Дорого',
            self::CHANGED_MIND => 'Передумал',
            self::BUYING_NEW => 'Купит новую технику',
            self::CALLED_ANOTHER_MASTER => 'Вызвал другого мастера',
            self::OTHER => 'Другое',
        };
    }
}
