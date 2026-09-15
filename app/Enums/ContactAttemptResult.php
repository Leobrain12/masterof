<?php

namespace App\Enums;

/**
 * Результат попытки дозвониться клиенту (ТЗ п.37).
 */
enum ContactAttemptResult: string
{
    case NO_ANSWER = 'NO_ANSWER';
    case BUSY = 'BUSY';
    case PHONE_OFF = 'PHONE_OFF';
    case WRONG_NUMBER = 'WRONG_NUMBER';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::NO_ANSWER => 'Не берёт трубку',
            self::BUSY => 'Занято',
            self::PHONE_OFF => 'Телефон выключен',
            self::WRONG_NUMBER => 'Неверный номер',
            self::OTHER => 'Другое',
        };
    }
}
