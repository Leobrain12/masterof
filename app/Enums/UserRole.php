<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPERADMIN = 'SUPERADMIN';
    case ADMIN = 'ADMIN';
    case MASTER = 'MASTER';

    public function label(): string
    {
        return match ($this) {
            self::SUPERADMIN => 'Суперадминистратор',
            self::ADMIN => 'Администратор',
            self::MASTER => 'Мастер',
        };
    }

    public function isAdminLike(): bool
    {
        return $this === self::SUPERADMIN || $this === self::ADMIN;
    }
}
