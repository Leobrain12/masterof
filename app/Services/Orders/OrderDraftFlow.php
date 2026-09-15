<?php

namespace App\Services\Orders;

use App\Enums\OrderDraftStep;
use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\Brand;
use App\Models\GeoZone;
use App\Models\Master;
use App\Models\Order;
use App\Models\OrderDraft;
use App\Models\OrderVisit;
use App\Models\Symptom;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Пошаговый сценарий создания заявки (ТЗ п.18.1–18.12) с состоянием на сервере,
 * а не в памяти процесса (ТЗ п.110) — чтобы рестарт бота не терял заполненную форму.
 *
 * ADDRESS_ZONE — шаг сверх исходных 12 (см. App\Enums\OrderDraftStep). "Назад" с
 * экрана подтверждения (было в макете рядом с [Создать][Отмена]) в этой фазе не
 * реализован — только отмена целиком; постраничный откат оставлен на потом.
 */
class OrderDraftFlow
{
    private const TTL_MINUTES = 30;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly MasterMatcher $matcher,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
    ) {}

    public function start(User $admin, int $chatId): void
    {
        OrderDraft::query()->where('created_by_user_id', $admin->id)->delete();

        $draft = OrderDraft::query()->create([
            'created_by_user_id' => $admin->id,
            'step' => OrderDraftStep::APPLIANCE_TYPE,
            'payload' => [],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $this->promptApplianceType($chatId);
    }

    public function handle(User $admin, OrderDraft $draft, int $chatId, ?string $text, ?string $callback): void
    {
        if ($callback === 'cancel') {
            $draft->delete();
            $this->telegram->sendMessage($chatId, 'Создание заявки отменено.');

            return;
        }

        $draft->expires_at = now()->addMinutes(self::TTL_MINUTES);
        $draft->save();

        match ($draft->step) {
            OrderDraftStep::APPLIANCE_TYPE => $this->handleApplianceType($draft, $chatId, $callback),
            OrderDraftStep::BRAND => $this->handleBrand($draft, $chatId, $callback),
            OrderDraftStep::MODEL => $this->handleModel($draft, $chatId, $text, $callback),
            OrderDraftStep::SYMPTOM => $this->handleSymptom($draft, $chatId, $callback),
            OrderDraftStep::SYMPTOM_CUSTOM => $this->handleSymptomCustom($draft, $chatId, $text),
            OrderDraftStep::DESCRIPTION => $this->handleDescription($draft, $chatId, $text, $callback),
            OrderDraftStep::CUSTOMER_NAME => $this->handleCustomerName($draft, $chatId, $text),
            OrderDraftStep::CUSTOMER_PHONE => $this->handleCustomerPhone($draft, $chatId, $text),
            OrderDraftStep::ADDRESS => $this->handleAddress($draft, $chatId, $text),
            OrderDraftStep::ADDRESS_ZONE => $this->handleAddressZone($draft, $chatId, $callback),
            OrderDraftStep::DATE => $this->handleDate($draft, $chatId, $callback),
            OrderDraftStep::DATE_CUSTOM => $this->handleDateCustom($draft, $chatId, $text),
            OrderDraftStep::TIME_SLOT => $this->handleTimeSlot($draft, $chatId, $callback),
            OrderDraftStep::TIME_SLOT_CUSTOM => $this->handleTimeSlotCustom($draft, $chatId, $text),
            OrderDraftStep::MASTER => $this->handleMaster($draft, $chatId, $callback),
            OrderDraftStep::CONFIRM => $this->handleConfirm($admin, $draft, $chatId, $callback),
        };
    }

    // --- Шаг 1: тип техники (18.1) ---

    private function promptApplianceType(int $chatId): void
    {
        $types = ApplianceType::query()->where('is_active', true)->orderBy('sort_order')->get();

        $this->telegram->sendMessage($chatId, 'Тип техники:', $this->buttonGrid(
            $types->map(fn ($t) => ['text' => $t->name, 'callback_data' => "draft:appliance_type:{$t->id}"])->all()
        ));
    }

    private function handleApplianceType(OrderDraft $draft, int $chatId, ?string $callback): void
    {
        if (! $callback || ! str_starts_with($callback, 'appliance_type:')) {
            $this->promptApplianceType($chatId);

            return;
        }

        $id = (int) Str::after($callback, 'appliance_type:');
        $draft->put('appliance_type_id', $id);
        $this->advance($draft, OrderDraftStep::BRAND);
        $this->promptBrand($chatId);
    }

    // --- Шаг 2: бренд (18.2) ---

    private function promptBrand(int $chatId): void
    {
        $brands = Brand::query()->where('is_active', true)->orderBy('sort_order')->get();

        $buttons = $brands->map(fn ($b) => ['text' => $b->name, 'callback_data' => "draft:brand:{$b->id}"])->all();
        $buttons[] = ['text' => 'Не знаю', 'callback_data' => 'draft:brand:unknown'];

        $this->telegram->sendMessage($chatId, 'Бренд:', $this->buttonGrid($buttons));
    }

    private function handleBrand(OrderDraft $draft, int $chatId, ?string $callback): void
    {
        if (! $callback || ! str_starts_with($callback, 'brand:')) {
            $this->promptBrand($chatId);

            return;
        }

        $value = Str::after($callback, 'brand:');
        $draft->put('brand_id', $value === 'unknown' ? null : (int) $value);
        $this->advance($draft, OrderDraftStep::MODEL);
        $this->promptModel($chatId);
    }

    // --- Шаг 3: модель (18.3) ---

    private function promptModel(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, 'Модель (или пропусти):', [
            'inline_keyboard' => [[['text' => 'Пропустить', 'callback_data' => 'draft:model:skip']]],
        ]);
    }

    private function handleModel(OrderDraft $draft, int $chatId, ?string $text, ?string $callback): void
    {
        $draft->put('model', $callback === 'model:skip' ? null : ($text ? trim($text) : null));

        if (! $callback && ! $text) {
            $this->promptModel($chatId);

            return;
        }

        $this->advance($draft, OrderDraftStep::SYMPTOM);
        $this->promptSymptom($draft, $chatId);
    }

    // --- Шаг 4: проблема (18.4) ---

    private function promptSymptom(OrderDraft $draft, int $chatId): void
    {
        $applianceTypeId = $draft->get('appliance_type_id');

        $symptoms = Symptom::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('appliance_type_id', $applianceTypeId)->orWhereNull('appliance_type_id'))
            ->orderBy('sort_order')
            ->get();

        $buttons = $symptoms->map(fn ($s) => ['text' => $s->text, 'callback_data' => "draft:symptom:{$s->id}"])->all();
        $buttons[] = ['text' => 'Ввести вручную', 'callback_data' => 'draft:symptom:manual'];

        $this->telegram->sendMessage($chatId, 'Проблема:', $this->buttonGrid($buttons, 1));
    }

    private function handleSymptom(OrderDraft $draft, int $chatId, ?string $callback): void
    {
        if (! $callback || ! str_starts_with($callback, 'symptom:')) {
            $this->promptSymptom($draft, $chatId);

            return;
        }

        $value = Str::after($callback, 'symptom:');

        if ($value === 'manual') {
            $this->advance($draft, OrderDraftStep::SYMPTOM_CUSTOM);
            $this->telegram->sendMessage($chatId, 'Опиши проблему своими словами:');

            return;
        }

        $symptom = Symptom::query()->find((int) $value);
        $draft->put('symptom', $symptom?->text ?? 'Не указана');
        $this->advance($draft, OrderDraftStep::DESCRIPTION);
        $this->promptDescription($chatId);
    }

    private function handleSymptomCustom(OrderDraft $draft, int $chatId, ?string $text): void
    {
        if (! $text) {
            $this->telegram->sendMessage($chatId, 'Опиши проблему своими словами:');

            return;
        }

        $draft->put('symptom', trim($text));
        $this->advance($draft, OrderDraftStep::DESCRIPTION);
        $this->promptDescription($chatId);
    }

    // --- Шаг 5: описание (18.5) ---

    private function promptDescription(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, 'Комментарий администратора (или пропусти):', [
            'inline_keyboard' => [[['text' => 'Пропустить', 'callback_data' => 'draft:description:skip']]],
        ]);
    }

    private function handleDescription(OrderDraft $draft, int $chatId, ?string $text, ?string $callback): void
    {
        if (! $callback && ! $text) {
            $this->promptDescription($chatId);

            return;
        }

        $draft->put('description', $callback === 'description:skip' ? null : trim((string) $text));
        $this->advance($draft, OrderDraftStep::CUSTOMER_NAME);
        $this->telegram->sendMessage($chatId, 'Имя клиента:');
    }

    // --- Шаг 6: клиент (18.6) ---

    private function handleCustomerName(OrderDraft $draft, int $chatId, ?string $text): void
    {
        if (! $text || trim($text) === '') {
            $this->telegram->sendMessage($chatId, 'Имя клиента:');

            return;
        }

        $draft->put('customer_name', trim($text));
        $this->advance($draft, OrderDraftStep::CUSTOMER_PHONE);
        $this->telegram->sendMessage($chatId, 'Телефон клиента:');
    }

    // --- Шаг 7: телефон (18.7) ---

    private function handleCustomerPhone(OrderDraft $draft, int $chatId, ?string $text): void
    {
        $phone = $text ? $this->normalizePhone($text) : null;

        if (! $phone) {
            $this->telegram->sendMessage($chatId, "Похоже, это не телефон. Пришли в формате +7XXXXXXXXXX:");

            return;
        }

        $draft->put('customer_phone', $phone);
        $this->advance($draft, OrderDraftStep::ADDRESS);
        $this->telegram->sendMessage($chatId, 'Адрес:');
    }

    // --- Шаг 8: адрес (18.8) + зона обслуживания (наше расширение) ---

    private function handleAddress(OrderDraft $draft, int $chatId, ?string $text): void
    {
        if (! $text || trim($text) === '') {
            $this->telegram->sendMessage($chatId, 'Адрес:');

            return;
        }

        $draft->put('address', trim($text));
        $this->advance($draft, OrderDraftStep::ADDRESS_ZONE);
        $this->promptAddressZone($chatId);
    }

    private function promptAddressZone(int $chatId): void
    {
        $zones = GeoZone::query()->where('is_active', true)->orderBy('sort_order')->get();

        $buttons = $zones->map(fn ($z) => ['text' => $z->name, 'callback_data' => "draft:zone:{$z->id}"])->all();
        $buttons[] = ['text' => 'Не знаю', 'callback_data' => 'draft:zone:skip'];

        $this->telegram->sendMessage($chatId, 'Район обслуживания (нужен, чтобы подобрать мастера рядом):', $this->buttonGrid($buttons));
    }

    private function handleAddressZone(OrderDraft $draft, int $chatId, ?string $callback): void
    {
        if (! $callback || ! str_starts_with($callback, 'zone:')) {
            $this->promptAddressZone($chatId);

            return;
        }

        $value = Str::after($callback, 'zone:');
        $draft->put('geo_zone_id', $value === 'skip' ? null : (int) $value);
        $this->advance($draft, OrderDraftStep::DATE);
        $this->promptDate($chatId);
    }

    // --- Шаг 9: дата (18.9) ---

    private function promptDate(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, 'Дата визита:', [
            'inline_keyboard' => [[
                ['text' => 'Сегодня', 'callback_data' => 'draft:date:today'],
                ['text' => 'Завтра', 'callback_data' => 'draft:date:tomorrow'],
                ['text' => 'Послезавтра', 'callback_data' => 'draft:date:day_after'],
            ], [
                ['text' => 'Выбрать дату', 'callback_data' => 'draft:date:custom'],
            ]],
        ]);
    }

    private function handleDate(OrderDraft $draft, int $chatId, ?string $callback): void
    {
        $map = ['today' => 0, 'tomorrow' => 1, 'day_after' => 2];
        $value = $callback ? Str::after($callback, 'date:') : null;

        if ($value === 'custom') {
            $this->advance($draft, OrderDraftStep::DATE_CUSTOM);
            $this->telegram->sendMessage($chatId, 'Введи дату в формате ДД.ММ (например 16.08):');

            return;
        }

        if (! $value || ! array_key_exists($value, $map)) {
            $this->promptDate($chatId);

            return;
        }

        $draft->put('visit_date', now()->addDays($map[$value])->toDateString());
        $this->advance($draft, OrderDraftStep::TIME_SLOT);
        $this->promptTimeSlot($chatId);
    }

    private function handleDateCustom(OrderDraft $draft, int $chatId, ?string $text): void
    {
        $date = $text ? $this->parseCustomDate($text) : null;

        if (! $date) {
            $this->telegram->sendMessage($chatId, 'Не получилось разобрать дату. Формат ДД.ММ, например 16.08:');

            return;
        }

        $draft->put('visit_date', $date->toDateString());
        $this->advance($draft, OrderDraftStep::TIME_SLOT);
        $this->promptTimeSlot($chatId);
    }

    // --- Шаг 10: слот (18.10) ---

    private function promptTimeSlot(int $chatId): void
    {
        $slots = TimeSlot::query()->where('is_active', true)->orderBy('sort_order')->get();

        $buttons = $slots->map(fn ($s) => ['text' => $s->label, 'callback_data' => "draft:slot:{$s->id}"])->all();
        $buttons[] = ['text' => 'Другое', 'callback_data' => 'draft:slot:custom'];

        $this->telegram->sendMessage($chatId, 'Временной слот:', $this->buttonGrid($buttons));
    }

    private function handleTimeSlot(OrderDraft $draft, int $chatId, ?string $callback): void
    {
        $value = $callback ? Str::after($callback, 'slot:') : null;

        if ($value === 'custom') {
            $this->advance($draft, OrderDraftStep::TIME_SLOT_CUSTOM);
            $this->telegram->sendMessage($chatId, 'Укажи время текстом (например 19:00–20:00):');

            return;
        }

        if (! $value || ! ctype_digit($value)) {
            $this->promptTimeSlot($chatId);

            return;
        }

        $slot = TimeSlot::query()->find((int) $value);

        if (! $slot) {
            $this->promptTimeSlot($chatId);

            return;
        }

        $draft->put('time_slot_id', $slot->id);
        $draft->put('time_slot_label', $slot->label);
        $this->advance($draft, OrderDraftStep::MASTER);
        $this->promptMaster($draft, $chatId);
    }

    private function handleTimeSlotCustom(OrderDraft $draft, int $chatId, ?string $text): void
    {
        if (! $text || trim($text) === '') {
            $this->telegram->sendMessage($chatId, 'Укажи время текстом (например 19:00–20:00):');

            return;
        }

        $draft->put('time_slot_id', null);
        $draft->put('time_slot_label', trim($text));
        $this->advance($draft, OrderDraftStep::MASTER);
        $this->promptMaster($draft, $chatId);
    }

    // --- Шаг 11: мастер (18.11) ---

    private function promptMaster(OrderDraft $draft, int $chatId): void
    {
        $result = $this->matcher->forOrder(
            $draft->get('appliance_type_id'),
            $draft->get('brand_id'),
            $draft->get('geo_zone_id'),
        );

        $masters = $result['masters'];

        if ($masters->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                'Нет ни одного активного мастера в системе. Добавь мастера и вернись к заявке позже — черновик сохранён.'
            );

            return;
        }

        $lines = $result['exact']
            ? ['Подходящие мастера:']
            : ['Точных совпадений нет, показаны все доступные:'];

        foreach ($masters as $m) {
            $brands = $m->brands->pluck('name')->join(', ') ?: '—';
            $zones = $m->geoZones->pluck('name')->join(', ') ?: '—';
            $lines[] = "{$m->name} — {$brands} · {$zones}";
        }

        $buttons = $masters->map(fn ($m) => ['text' => $m->name, 'callback_data' => "draft:master:{$m->id}"])->all();

        $this->telegram->sendMessage($chatId, implode("\n", $lines), $this->buttonGrid($buttons));
    }

    private function handleMaster(OrderDraft $draft, int $chatId, ?string $callback): void
    {
        $masterId = $callback ? Str::after($callback, 'master:') : null;
        $master = $masterId ? Master::query()->find($masterId) : null;

        if (! $master) {
            $this->promptMaster($draft, $chatId);

            return;
        }

        $draft->put('master_id', $master->id);
        $this->advance($draft, OrderDraftStep::CONFIRM);
        $this->promptConfirm($draft, $chatId);
    }

    // --- Шаг 12: подтверждение (18.12) ---

    private function promptConfirm(OrderDraft $draft, int $chatId): void
    {
        $applianceType = ApplianceType::find($draft->get('appliance_type_id'));
        $brand = $draft->get('brand_id') ? Brand::find($draft->get('brand_id')) : null;
        $master = Master::find($draft->get('master_id'));
        $date = Carbon::parse($draft->get('visit_date'));

        $lines = [
            'Новая заявка',
            $applianceType->name.($brand ? " {$brand->name}" : ''),
        ];

        if ($draft->get('model')) {
            $lines[] = "Модель: {$draft->get('model')}";
        }

        $lines[] = "Проблема: {$draft->get('symptom')}";

        if ($draft->get('description')) {
            $lines[] = "Комментарий: {$draft->get('description')}";
        }

        $lines[] = "Клиент: {$draft->get('customer_name')}";
        $lines[] = "Телефон: {$draft->get('customer_phone')}";
        $lines[] = "Адрес: {$draft->get('address')}";
        $lines[] = 'Дата: '.$date->translatedFormat('d.m.Y');
        $lines[] = "Время: {$draft->get('time_slot_label')}";
        $lines[] = "Мастер: {$master->name}";

        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'inline_keyboard' => [[
                ['text' => '✅ Создать', 'callback_data' => 'draft:confirm:create'],
                ['text' => '✖️ Отмена', 'callback_data' => 'draft:cancel'],
            ]],
        ]);
    }

    private function handleConfirm(User $admin, OrderDraft $draft, int $chatId, ?string $callback): void
    {
        if ($callback !== 'confirm:create') {
            $this->promptConfirm($draft, $chatId);

            return;
        }

        $order = Order::query()->create([
            'customer_name' => $draft->get('customer_name'),
            'customer_phone' => $draft->get('customer_phone'),
            'appliance_type_id' => $draft->get('appliance_type_id'),
            'brand_id' => $draft->get('brand_id'),
            'model' => $draft->get('model'),
            'symptom' => $draft->get('symptom'),
            'description' => $draft->get('description'),
            'address' => $draft->get('address'),
            'geo_zone_id' => $draft->get('geo_zone_id'),
            'visit_date' => $draft->get('visit_date'),
            'time_slot_label' => $draft->get('time_slot_label'),
            'time_slot_id' => $draft->get('time_slot_id'),
            'status' => OrderStatus::NEW,
            'created_by' => $admin->id,
        ]);

        $order = $this->statusMachine->transition(
            $order,
            OrderStatus::ASSIGNED,
            $admin,
            attributes: ['master_id' => $draft->get('master_id')],
        );

        // Visit #1 — сам факт заявки уже подразумевает один визит (ТЗ п.33-34);
        // дальнейшие визиты создаёт OrderVisitFlow при планировании повторного.
        OrderVisit::query()->create([
            'order_id' => $order->id,
            'visit_number' => 1,
            'visit_date' => $order->visit_date,
            'time_slot_label' => $order->time_slot_label,
            'master_id' => $order->master_id,
            'status' => 'SCHEDULED',
            'reason' => 'Диагностика',
        ]);

        $draft->delete();

        $order->load('master.user');

        $this->notifier->orderCreated($order, $admin);
        $this->notifier->assignedToMaster($order);
    }

    // --- helpers ---

    private function advance(OrderDraft $draft, OrderDraftStep $step): void
    {
        $draft->step = $step;
        $draft->save();
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function buttonGrid(array $buttons, int $perRow = 2): array
    {
        return ['inline_keyboard' => array_chunk($buttons, max(1, $perRow))];
    }

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

    private function parseCustomDate(string $text): ?Carbon
    {
        $text = trim($text);

        foreach (['d.m.Y', 'd.m.y', 'd.m'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $text);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if ($date === false) {
                continue;
            }

            if (! str_contains($format, 'Y') && ! str_contains($format, 'y')) {
                $date->year(now()->year);

                if ($date->isPast() && ! $date->isToday()) {
                    $date->addYear();
                }
            } elseif ($date->isPast() && ! $date->isToday()) {
                return null;
            }

            return $date->startOfDay();
        }

        return null;
    }
}
