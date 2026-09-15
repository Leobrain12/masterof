<?php

namespace App\Enums;

/**
 * Этап, на котором снято фото/видео (ТЗ п.51) — позволяет потом показывать
 * "до ремонта → диагностика → процесс → после ремонта" отдельными группами.
 */
enum MediaStage: string
{
    case BEFORE = 'BEFORE';
    case DIAGNOSTICS = 'DIAGNOSTICS';
    case DURING = 'DURING';
    case AFTER = 'AFTER';
    case PART = 'PART';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::BEFORE => 'До ремонта',
            self::DIAGNOSTICS => 'Диагностика',
            self::DURING => 'В процессе',
            self::AFTER => 'После ремонта',
            self::PART => 'Деталь',
            self::OTHER => 'Другое',
        };
    }
}
