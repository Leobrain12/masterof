<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Выдаёт SUPERADMIN твоему собственному telegram_user_id из .env (TELEGRAM_OWNER_ID).
 * Без него первый /start в боте покажет «Доступ не предоставлен» — это ожидаемо
 * (ТЗ п.6.1: самостоятельного создания аккаунта нет, доступ выдаётся только вручную).
 */
class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        $ownerId = config('services.telegram.owner_id');

        if (! $ownerId) {
            $this->command?->warn(
                'TELEGRAM_OWNER_ID не задан в .env — SUPERADMIN не создан. '.
                'Узнай свой telegram_user_id у @userinfobot, добавь в .env и перезапусти db:seed.'
            );

            return;
        }

        User::query()->updateOrCreate(
            ['telegram_user_id' => $ownerId],
            [
                'role' => UserRole::SUPERADMIN,
                'name' => 'Владелец',
                'is_active' => true,
            ]
        );

        $this->command?->info("SUPERADMIN выдан telegram_user_id={$ownerId}.");
    }
}
