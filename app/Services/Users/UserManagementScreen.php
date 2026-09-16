<?php

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Экран «Пользователи» (только SUPERADMIN, UI-ТЗ A1). Сознательно НЕ меняет
 * роль — выдача ADMIN/SUPERADMIN остаётся ручным tinker (см.
 * vault/Решения.md#master:add без создания ADMIN/SUPERADMIN), это
 * осознанный порог трения для редкого и доверенного действия. Экран только
 * показывает состав и включает/выключает доступ уже существующим аккаунтам.
 */
class UserManagementScreen
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function list(int $chatId): void
    {
        $users = User::query()->orderBy('name')->get();

        foreach (UserRole::cases() as $role) {
            $roleUsers = $users->where('role', $role);

            if ($roleUsers->isEmpty()) {
                continue;
            }

            $this->telegram->sendMessage($chatId, "— {$role->label()} —");

            foreach ($roleUsers as $user) {
                $this->sendUserCard($chatId, $user);
            }
        }

        $this->telegram->sendMessage($chatId, 'Завести нового мастера:', [
            'inline_keyboard' => [[['text' => '➕ Добавить мастера', 'callback_data' => 'users:add_master']]],
        ]);
    }

    public function toggle(User $admin, int $chatId, string $targetUserId): void
    {
        if ($targetUserId === $admin->id) {
            $this->telegram->sendMessage($chatId, 'Нельзя деактивировать самого себя.');

            return;
        }

        $target = User::query()->find($targetUserId);

        if (! $target) {
            $this->telegram->sendMessage($chatId, 'Пользователь не найден.');

            return;
        }

        $target->update(['is_active' => ! $target->is_active]);

        $status = $target->is_active ? 'включён' : 'отключён';
        $this->telegram->sendMessage($chatId, "{$target->name}: доступ {$status}.");
    }

    private function sendUserCard(int $chatId, User $user): void
    {
        $status = $user->is_active ? 'активен' : 'отключён';
        $phone = $user->phone ? " · {$user->phone}" : '';
        $text = "{$user->name}{$phone} · ID {$user->telegram_user_id} — {$status}";

        $buttonLabel = $user->is_active ? '🚫 Деактивировать' : '✅ Активировать';

        $this->telegram->sendMessage($chatId, $text, [
            'inline_keyboard' => [[['text' => $buttonLabel, 'callback_data' => "users:toggle:{$user->id}"]]],
        ]);
    }
}
