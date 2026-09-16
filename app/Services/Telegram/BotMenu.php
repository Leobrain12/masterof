<?php

namespace App\Services\Telegram;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Главные меню на кнопках (ТЗ п.70 — ADMIN, п.66 — MASTER, UI-ТЗ A1 — Пользователи только у SUPERADMIN).
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
        return [
            'keyboard' => $this->rowsFor($user),
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /**
     * Текст совпадает с одной из кнопок главного меню этой роли — используется
     * диспетчером апдейтов как запасной выход из активного сценария (черновик
     * заявки, PendingInput), чтобы нажатие кнопки меню не терялось в его вводе.
     */
    public function isMenuCommand(User $user, string $text): bool
    {
        return in_array($text, array_merge(...$this->rowsFor($user)), true);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function rowsFor(User $user): array
    {
        return match ($user->role) {
            UserRole::SUPERADMIN, UserRole::ADMIN => $this->adminRows($user),
            UserRole::MASTER => $this->masterRows(),
        };
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
