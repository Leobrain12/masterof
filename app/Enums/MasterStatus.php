<?php

namespace App\Enums;

enum MasterStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DAY_OFF = 'DAY_OFF';
    case VACATION = 'VACATION';
    case DISABLED = 'DISABLED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'На линии',
            self::DAY_OFF => 'Выходной',
            self::VACATION => 'Отпуск',
            self::DISABLED => 'Отключён',
        };
    }

    /**
     * Мастеру в этом статусе нельзя назначить новый заказ (см. ТЗ п.8.1).
     */
    public function isAssignable(): bool
    {
        return $this === self::ACTIVE || $this === self::DAY_OFF;
    }
}
