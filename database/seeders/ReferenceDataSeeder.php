<?php

namespace Database\Seeders;

use App\Models\ApplianceType;
use App\Models\Brand;
use App\Models\GeoZone;
use App\Models\Symptom;
use App\Models\TimeSlot;
use Illuminate\Database\Seeder;

/**
 * Стартовые справочники из ТЗ (п.18.1, 18.2, 18.10, 9).
 * Гео-зоны — пример, перед реальным запуском замени на свои районы обслуживания.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedApplianceTypes();
        $this->seedBrands();
        $this->seedGeoZones();
        $this->seedTimeSlots();
        $this->seedSymptoms();
    }

    private function seedApplianceTypes(): void
    {
        $items = [
            'Стиральная машина',
            'Холодильник',
            'Посудомоечная машина',
            'Сушильная машина',
            'Духовой шкаф',
            'Варочная панель',
            'Другое',
        ];

        foreach ($items as $i => $name) {
            ApplianceType::query()->firstOrCreate(['name' => $name], ['sort_order' => $i]);
        }
    }

    private function seedBrands(): void
    {
        $items = ['Bosch', 'Siemens', 'Samsung', 'LG', 'Miele', 'Liebherr', 'Electrolux', 'Indesit', 'Другое'];

        foreach ($items as $i => $name) {
            Brand::query()->firstOrCreate(['name' => $name], ['sort_order' => $i]);
        }
    }

    private function seedGeoZones(): void
    {
        // Пример — замени на реальные районы обслуживания перед запуском.
        $items = ['Москва ЦАО', 'Москва ЗАО', 'Москва ЮЗАО', 'Одинцово', 'Химки', 'Балашиха'];

        foreach ($items as $i => $name) {
            GeoZone::query()->firstOrCreate(['name' => $name], ['sort_order' => $i]);
        }
    }

    private function seedTimeSlots(): void
    {
        $items = [
            ['09:00', '12:00'],
            ['12:00', '15:00'],
            ['15:00', '18:00'],
            ['18:00', '21:00'],
        ];

        foreach ($items as $i => [$from, $to]) {
            TimeSlot::query()->firstOrCreate(
                ['label' => "{$from}–{$to}"],
                ['starts_at' => $from, 'ends_at' => $to, 'sort_order' => $i]
            );
        }
    }

    private function seedSymptoms(): void
    {
        $typeId = fn (string $name) => ApplianceType::query()->where('name', $name)->value('id');

        // null = общая проблема, показывается для любой техники (ТЗ п.18.4).
        $items = [
            [null, 'Не включается'],
            [null, 'Другая проблема (описать вручную)'],
            [$typeId('Стиральная машина'), 'Не сливает воду'],
            [$typeId('Стиральная машина'), 'Не крутит барабан'],
            [$typeId('Стиральная машина'), 'Течёт вода'],
            [$typeId('Холодильник'), 'Не морозит'],
            [$typeId('Холодильник'), 'Шумит компрессор'],
            [$typeId('Холодильник'), 'Течёт из-под холодильника'],
            [$typeId('Посудомоечная машина'), 'Не сливает воду'],
            [$typeId('Посудомоечная машина'), 'Не моет посуду'],
            [$typeId('Сушильная машина'), 'Не сушит'],
            [$typeId('Сушильная машина'), 'Не крутит барабан'],
            [$typeId('Духовой шкаф'), 'Не греет'],
            [$typeId('Духовой шкаф'), 'Не работает подсветка/дисплей'],
            [$typeId('Варочная панель'), 'Не работает конфорка'],
            [$typeId('Варочная панель'), 'Не включается сенсор'],
        ];

        foreach ($items as $i => [$applianceTypeId, $text]) {
            Symptom::query()->firstOrCreate(
                ['appliance_type_id' => $applianceTypeId, 'text' => $text],
                ['sort_order' => $i]
            );
        }
    }
}
