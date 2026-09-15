<?php

namespace App\Console\Commands;

use App\Enums\MasterStatus;
use App\Enums\UserRole;
use App\Models\ApplianceType;
use App\Models\Brand;
use App\Models\GeoZone;
use App\Models\Master;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * До этой команды завести нового мастера можно было только руками через tinker/SQL
 * (см. аудит Фазы 07, vault/Фазы/Фаза 07 — Прод-готовность.md). Заводить новых
 * ADMIN/SUPERADMIN тем же способом сознательно не стали — это редкое и более
 * доверенное действие, для него ручной tinker остаётся нормальным вариантом.
 *
 * --appliance обязателен, не опционален: MasterMatcher фильтрует специализацию
 * жёстко (см. app/Services/Orders/MasterMatcher.php) — мастер без неё никогда
 * не попадёт ни в одну подборку и будет невидим для системы назначения.
 */
class MasterAdd extends Command
{
    protected $signature = 'master:add
        {telegram_id : telegram_user_id мастера (узнать у @userinfobot)}
        {name : Имя мастера для карточек заказа}
        {phone : Телефон мастера}
        {--appliance=* : Название типа техники (можно несколько раз), обязательно хотя бы одно}
        {--brand=* : Название бренда (можно несколько раз, необязательно)}
        {--zone=* : Название геозоны (можно несколько раз, необязательно)}';

    protected $description = 'Завести нового мастера: User(role=MASTER) + Master + специализация';

    public function handle(): int
    {
        $telegramId = (int) $this->argument('telegram_id');

        if (User::query()->where('telegram_user_id', $telegramId)->exists()) {
            $this->error("Пользователь с telegram_user_id={$telegramId} уже существует.");

            return self::FAILURE;
        }

        if ($this->option('appliance') === []) {
            $this->error('Нужна хотя бы одна специализация: --appliance="Стиральные машины". '.
                'Без неё MasterMatcher никогда не подберёт этого мастера ни на один заказ.');

            return self::FAILURE;
        }

        $applianceTypes = $this->resolveByName(ApplianceType::class, $this->option('appliance'), 'типы техники');
        if ($applianceTypes === null) {
            return self::FAILURE;
        }

        $brands = $this->resolveByName(Brand::class, $this->option('brand'), 'бренды');
        if ($brands === null) {
            return self::FAILURE;
        }

        $zones = $this->resolveByName(GeoZone::class, $this->option('zone'), 'геозоны');
        if ($zones === null) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($telegramId, $applianceTypes, $brands, $zones): void {
            $user = User::query()->create([
                'telegram_user_id' => $telegramId,
                'role' => UserRole::MASTER,
                'name' => $this->argument('name'),
                'phone' => $this->argument('phone'),
                'is_active' => true,
            ]);

            $master = Master::query()->create([
                'user_id' => $user->id,
                'name' => $this->argument('name'),
                'phone' => $this->argument('phone'),
                'status' => MasterStatus::ACTIVE,
                'is_active' => true,
            ]);

            $master->applianceTypes()->attach($applianceTypes->pluck('id'));

            if ($brands->isNotEmpty()) {
                $master->brands()->attach($brands->pluck('id'));
            }

            if ($zones->isNotEmpty()) {
                $master->geoZones()->attach($zones->pluck('id'));
            }
        });

        $this->info(sprintf(
            'Мастер «%s» добавлен: telegram_user_id=%d, специализация: %s.',
            $this->argument('name'),
            $telegramId,
            $applianceTypes->pluck('name')->implode(', ')
        ));

        return self::SUCCESS;
    }

    /**
     * @param  class-string<ApplianceType|Brand|GeoZone>  $model
     * @param  list<string>  $names
     */
    private function resolveByName(string $model, array $names, string $label): ?Collection
    {
        if ($names === []) {
            return new Collection;
        }

        $found = $model::query()->whereIn('name', $names)->get();
        $missing = array_diff($names, $found->pluck('name')->all());

        if ($missing !== []) {
            $this->error("Не найдены {$label}: ".implode(', ', $missing));

            return null;
        }

        return $found;
    }
}
