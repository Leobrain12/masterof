<?php

namespace App\Services\Telegram;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Главные меню на кнопках (ТЗ п.70 — ADMIN, п.66 — MASTER, UI-ТЗ A1 — Пользователи только у SUPERADMIN).
 * На фазе 00 экраны за кнопками — заглушки, наполняются в следующих фазах.
 */
class BotMenu
{
    public function welcomeText(User $user): string
    {
        return match ($user->role) {
            UserRole::SUPERADMIN, UserRole::ADMIN => "Привет, {$user->name}. Ты в панели администратора Service Ops.",
            UserRole::MASTER => "Привет, {$user->name}. Это твой список заказов в Service Ops.",
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function keyboardFor(User $user): array
    {
        $rows = match ($user->role) {
            UserRole::SUPERADMIN, UserRole::ADMIN => $this->adminRows($user),
            UserRole::MASTER => $this->masterRows(),
        };

        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function adminRows(User $user): array
    {
        $rows = [
            ['Новая заявка', 'Сегодня'],
            ['Нераспределённые', 'Активные'],
            ['Мастера', 'Поиск'],
            ['Статистика'],
        ];

        if ($user->role === UserRole::SUPERADMIN) {
            $rows[] = ['Пользователи'];
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function masterRows(): array
    {
        return [
            ['Сегодня', 'Завтра'],
            ['Мои активные', 'История'],
            ['Моя статистика'],
        ];
    }
}
