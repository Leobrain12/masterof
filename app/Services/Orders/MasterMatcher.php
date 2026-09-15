<?php

namespace App\Services\Orders;

use App\Enums\MasterStatus;
use App\Models\Master;
use Illuminate\Support\Collection;

/**
 * Подбор мастеров под заявку (ТЗ п.18.11: "бот должен уметь отфильтровать неподходящих
 * мастеров"). Геокодирования адреса в MVP нет, поэтому зона — это то, что админ выбрал
 * руками на шаге адреса, а не вычисленная координата; при пустой зоне фильтр по геозоне
 * просто пропускается.
 *
 * Специализация (тип техники) — жёсткий фильтр, никогда не ослабляется: показать мастера
 * без нужной специализации значит нарушить смысл фильтрации. Бренд и география слабеют
 * по очереди, если точных совпадений нет — лучше показать более широкий список, чем пустой.
 */
class MasterMatcher
{
    /**
     * @return array{masters: Collection<int, Master>, exact: bool}
     */
    public function forOrder(int $applianceTypeId, ?int $brandId, ?int $geoZoneId): array
    {
        $base = Master::query()
            ->where('is_active', true)
            ->whereIn('status', [MasterStatus::ACTIVE->value, MasterStatus::DAY_OFF->value])
            ->whereHas('applianceTypes', fn ($q) => $q->where('appliance_types.id', $applianceTypeId))
            ->with(['brands', 'geoZones']);

        $tiers = [];

        if ($brandId && $geoZoneId) {
            $tiers[] = fn ($q) => $q->whereHas('brands', fn ($b) => $b->where('brands.id', $brandId))
                ->whereHas('geoZones', fn ($g) => $g->where('geo_zones.id', $geoZoneId));
        }

        if ($geoZoneId) {
            $tiers[] = fn ($q) => $q->whereHas('geoZones', fn ($g) => $g->where('geo_zones.id', $geoZoneId));
        }

        if ($brandId) {
            $tiers[] = fn ($q) => $q->whereHas('brands', fn ($b) => $b->where('brands.id', $brandId));
        }

        foreach ($tiers as $index => $narrow) {
            $matches = (clone $base)->tap($narrow)->get();

            if ($matches->isNotEmpty()) {
                return ['masters' => $matches, 'exact' => $index === 0 && $brandId && $geoZoneId];
            }
        }

        return ['masters' => $base->get(), 'exact' => false];
    }
}
