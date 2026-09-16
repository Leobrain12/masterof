<?php

namespace App\Services\Users;

use App\Enums\MasterStatus;
use App\Enums\UserRole;
use App\Models\ApplianceType;
use App\Models\Brand;
use App\Models\GeoZone;
use App\Models\Master;
use App\Models\PendingInput;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «➕ Добавить мастера» на экране «Пользователи» (SUPERADMIN) — тот же набор
 * полей и та же валидация, что у консольной `master:add` (telegram_id,
 * имя, телефон, специализация обязательна, бренды/зоны опциональны), просто
 * через диалог в боте вместо аргументов команды. Не выносим общий сервис —
 * это второе использование той же логики, не третье (см.
 * vault/Решения.md#DateSlotPicker — вынесено на третьем использовании).
 * Создаёт только MASTER — ADMIN/SUPERADMIN остаются ручным tinker.
 */
class MasterRegistrationFlow
{
    private const KIND = 'master_registration';

    public function __construct(private readonly TelegramClient $telegram) {}

    public function start(User $admin, int $chatId): void
    {
        PendingInput::query()->updateOrCreate(
            ['user_id' => $admin->id],
            ['kind' => self::KIND, 'payload' => ['step' => 'telegram_id'], 'expires_at' => now()->addMinutes(30)]
        );

        $this->telegram->sendMessage($chatId, 'Telegram ID нового мастера (узнать у @userinfobot):');
    }

    public function handle(User $admin, PendingInput $pending, int $chatId, ?string $text, ?string $callback): void
    {
        match ($pending->get('step')) {
            'telegram_id' => $this->handleTelegramId($pending, $chatId, $text),
            'name' => $this->handleName($pending, $chatId, $text),
            'phone' => $this->handlePhone($pending, $chatId, $text),
            'appliance' => $this->handleApplianceCallback($pending, $chatId, $callback),
            'brand' => $this->handleBrandCallback($pending, $chatId, $callback),
            'zone' => $this->handleZoneCallback($pending, $chatId, $callback),
            'confirm' => $this->handleConfirm($pending, $chatId, $callback),
            default => $pending->delete(),
        };
    }

    private function handleTelegramId(PendingInput $pending, int $chatId, ?string $text): void
    {
        $trimmed = trim((string) $text);
        $id = (int) $trimmed;

        if ($id <= 0 || (string) $id !== $trimmed) {
            $this->telegram->sendMessage($chatId, 'Нужно целое число — telegram_user_id мастера. Попробуй ещё раз:');

            return;
        }

        if (User::query()->where('telegram_user_id', $id)->exists()) {
            $this->telegram->sendMessage($chatId, "Пользователь с telegram_user_id={$id} уже существует. Введи другой ID:");

            return;
        }

        $pending->put('telegram_id', $id);
        $pending->put('step', 'name');
        $pending->save();

        $this->telegram->sendMessage($chatId, 'Имя мастера (для карточек заказа):');
    }

    private function handleName(PendingInput $pending, int $chatId, ?string $text): void
    {
        $name = trim((string) $text);

        if ($name === '') {
            $this->telegram->sendMessage($chatId, 'Имя не может быть пустым. Введи ещё раз:');

            return;
        }

        $pending->put('name', $name);
        $pending->put('step', 'phone');
        $pending->save();

        $this->telegram->sendMessage($chatId, 'Телефон мастера:');
    }

    private function handlePhone(PendingInput $pending, int $chatId, ?string $text): void
    {
        $phone = $text ? $this->normalizePhone($text) : null;

        if (! $phone) {
            $this->telegram->sendMessage($chatId, 'Похоже, это не телефон. Пришли в формате +7XXXXXXXXXX:');

            return;
        }

        $pending->put('phone', $phone);
        $pending->put('step', 'appliance');
        $pending->put('appliance_ids', []);
        $pending->save();

        $this->promptAppliance($chatId, []);
    }

    /**
     * @param  list<int>  $selectedIds
     */
    private function promptAppliance(int $chatId, array $selectedIds): void
    {
        $types = ApplianceType::query()->where('is_active', true)->orderBy('sort_order')->get();

        $buttons = $types->reject(fn (ApplianceType $t) => in_array($t->id, $selectedIds, true))
            ->map(fn (ApplianceType $t) => ['text' => $t->name, 'callback_data' => "master_reg:appliance:{$t->id}"])
            ->values()->all();
        $buttons[] = ['text' => 'Готово ✅', 'callback_data' => 'master_reg:appliance:done'];

        $selectedLabel = $selectedIds === [] ? '—' : $types->whereIn('id', $selectedIds)->pluck('name')->join(', ');

        $this->telegram->sendMessage(
            $chatId,
            "Специализация — обязательно хотя бы одна (иначе мастер невидим для подбора заявок). Выбрано: {$selectedLabel}",
            ['inline_keyboard' => array_chunk($buttons, 2)]
        );
    }

    private function handleApplianceCallback(PendingInput $pending, int $chatId, ?string $callback): void
    {
        $value = $callback ? Str::after($callback, 'appliance:') : null;

        /** @var list<int> $selected */
        $selected = $pending->get('appliance_ids', []);

        if ($value === 'done') {
            if ($selected === []) {
                $this->telegram->sendMessage($chatId, 'Нужна хотя бы одна специализация.');
                $this->promptAppliance($chatId, $selected);

                return;
            }

            $pending->put('step', 'brand');
            $pending->save();
            $this->promptBrand($chatId, []);

            return;
        }

        $id = (int) $value;

        if ($id > 0 && ! in_array($id, $selected, true)) {
            $selected[] = $id;
            $pending->put('appliance_ids', $selected);
            $pending->save();
        }

        $this->promptAppliance($chatId, $selected);
    }

    /**
     * @param  list<int>  $selectedIds
     */
    private function promptBrand(int $chatId, array $selectedIds): void
    {
        $brands = Brand::query()->where('is_active', true)->orderBy('sort_order')->get();

        $buttons = $brands->reject(fn (Brand $b) => in_array($b->id, $selectedIds, true))
            ->map(fn (Brand $b) => ['text' => $b->name, 'callback_data' => "master_reg:brand:{$b->id}"])
            ->values()->all();
        $buttons[] = ['text' => 'Готово ✅', 'callback_data' => 'master_reg:brand:done'];
        $buttons[] = ['text' => 'Пропустить', 'callback_data' => 'master_reg:brand:skip'];

        $selectedLabel = $selectedIds === [] ? '—' : $brands->whereIn('id', $selectedIds)->pluck('name')->join(', ');

        $this->telegram->sendMessage(
            $chatId,
            "Бренды — необязательно. Выбрано: {$selectedLabel}",
            ['inline_keyboard' => array_chunk($buttons, 2)]
        );
    }

    private function handleBrandCallback(PendingInput $pending, int $chatId, ?string $callback): void
    {
        $value = $callback ? Str::after($callback, 'brand:') : null;

        /** @var list<int> $selected */
        $selected = $pending->get('brand_ids', []);

        if ($value === 'done' || $value === 'skip') {
            $pending->put('brand_ids', $value === 'skip' ? [] : $selected);
            $pending->put('step', 'zone');
            $pending->save();
            $this->promptZone($chatId, []);

            return;
        }

        $id = (int) $value;

        if ($id > 0 && ! in_array($id, $selected, true)) {
            $selected[] = $id;
            $pending->put('brand_ids', $selected);
            $pending->save();
        }

        $this->promptBrand($chatId, $selected);
    }

    /**
     * @param  list<int>  $selectedIds
     */
    private function promptZone(int $chatId, array $selectedIds): void
    {
        $zones = GeoZone::query()->where('is_active', true)->orderBy('sort_order')->get();

        $buttons = $zones->reject(fn (GeoZone $z) => in_array($z->id, $selectedIds, true))
            ->map(fn (GeoZone $z) => ['text' => $z->name, 'callback_data' => "master_reg:zone:{$z->id}"])
            ->values()->all();
        $buttons[] = ['text' => 'Готово ✅', 'callback_data' => 'master_reg:zone:done'];
        $buttons[] = ['text' => 'Пропустить', 'callback_data' => 'master_reg:zone:skip'];

        $selectedLabel = $selectedIds === [] ? '—' : $zones->whereIn('id', $selectedIds)->pluck('name')->join(', ');

        $this->telegram->sendMessage(
            $chatId,
            "Геозоны — необязательно. Выбрано: {$selectedLabel}",
            ['inline_keyboard' => array_chunk($buttons, 2)]
        );
    }

    private function handleZoneCallback(PendingInput $pending, int $chatId, ?string $callback): void
    {
        $value = $callback ? Str::after($callback, 'zone:') : null;

        /** @var list<int> $selected */
        $selected = $pending->get('zone_ids', []);

        if ($value === 'done' || $value === 'skip') {
            $pending->put('zone_ids', $value === 'skip' ? [] : $selected);
            $pending->put('step', 'confirm');
            $pending->save();
            $this->promptConfirm($pending, $chatId);

            return;
        }

        $id = (int) $value;

        if ($id > 0 && ! in_array($id, $selected, true)) {
            $selected[] = $id;
            $pending->put('zone_ids', $selected);
            $pending->save();
        }

        $this->promptZone($chatId, $selected);
    }

    private function promptConfirm(PendingInput $pending, int $chatId): void
    {
        $applianceNames = ApplianceType::query()->whereIn('id', $pending->get('appliance_ids', []))->pluck('name')->join(', ');
        $brandNames = Brand::query()->whereIn('id', $pending->get('brand_ids', []))->pluck('name')->join(', ') ?: '—';
        $zoneNames = GeoZone::query()->whereIn('id', $pending->get('zone_ids', []))->pluck('name')->join(', ') ?: '—';

        $lines = [
            'Проверь данные нового мастера:',
            "Имя: {$pending->get('name')}",
            "Телефон: {$pending->get('phone')}",
            "Telegram ID: {$pending->get('telegram_id')}",
            "Специализация: {$applianceNames}",
            "Бренды: {$brandNames}",
            "Зоны: {$zoneNames}",
        ];

        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'inline_keyboard' => [[
                ['text' => '✅ Добавить', 'callback_data' => 'master_reg:confirm:create'],
                ['text' => '❌ Отмена', 'callback_data' => 'master_reg:confirm:cancel'],
            ]],
        ]);
    }

    private function handleConfirm(PendingInput $pending, int $chatId, ?string $callback): void
    {
        $value = $callback ? Str::after($callback, 'confirm:') : null;

        if ($value === 'cancel') {
            $pending->delete();
            $this->telegram->sendMessage($chatId, 'Отменено.');

            return;
        }

        if ($value !== 'create') {
            return;
        }

        $telegramId = (int) $pending->get('telegram_id');
        $name = (string) $pending->get('name');
        $phone = (string) $pending->get('phone');
        $applianceIds = $pending->get('appliance_ids', []);
        $brandIds = $pending->get('brand_ids', []);
        $zoneIds = $pending->get('zone_ids', []);

        $master = DB::transaction(function () use ($telegramId, $name, $phone, $applianceIds, $brandIds, $zoneIds): Master {
            $user = User::create([
                'telegram_user_id' => $telegramId,
                'role' => UserRole::MASTER,
                'name' => $name,
                'phone' => $phone,
                'is_active' => true,
            ]);

            $master = Master::create([
                'user_id' => $user->id,
                'name' => $name,
                'phone' => $phone,
                'status' => MasterStatus::ACTIVE,
                'is_active' => true,
            ]);

            $master->applianceTypes()->attach($applianceIds);

            if ($brandIds !== []) {
                $master->brands()->attach($brandIds);
            }

            if ($zoneIds !== []) {
                $master->geoZones()->attach($zoneIds);
            }

            return $master;
        });

        $pending->delete();
        $this->telegram->sendMessage($chatId, "Мастер «{$master->name}» добавлен: telegram_user_id={$telegramId}.");
    }

    /**
     * Копия приватного метода OrderDraftFlow::normalizePhone() — второе
     * использование, не третье, не выносим (см. класс-докблок).
     */
    private function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            return '+7'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '+7'.$digits;
        }

        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            return '+'.$digits;
        }

        return null;
    }
}
