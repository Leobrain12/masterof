<?php

namespace App\Enums;

/**
 * Причины отказа мастера от заявки (ТЗ п.22) — обязательны при отказе.
 */
enum OrderDeclineReason: string
{
    case NO_TIME = 'NO_TIME';
    case WRONG_SPECIALTY = 'WRONG_SPECIALTY';
    case TOO_FAR = 'TOO_FAR';
    case NO_TOOL = 'NO_TOOL';
    case NO_PART = 'NO_PART';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::NO_TIME => 'Нет времени',
            self::WRONG_SPECIALTY => 'Не моя специализация',
            self::TOO_FAR => 'Слишком далеко',
            self::NO_TOOL => 'Нет нужного инструмента',
            self::NO_PART => 'Нет нужной детали',
            self::OTHER => 'Другая причина',
        };
    }
}
