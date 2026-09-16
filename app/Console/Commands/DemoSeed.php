<?php

namespace App\Console\Commands;

use App\Enums\MasterStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\ApplianceType;
use App\Models\Master;
use App\Models\Order;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Демо-данные для РУЧНОГО прогона бота живыми руками (не для автотестов —
 * те строят фикстуры сами, см. tests/Feature/QaScenariosTest.php). Статус
 * заявки выставляется напрямую (Order::create со статусом NEW, затем
 * update()), как в QaScenariosTest::makeOrderAtStatus() — не через
 * OrderStatusMachine::transition(), чтобы посев не слал реальные
 * Telegram-уведомления и не писал историю переходов, которых по факту не было.
 *
 * Все посеянные записи помечены: имена мастеров/клиентов — префиксом TAG,
 * заявки — admin_comment=TAG — чтобы `demo:seed --clear` находил именно их
 * и не задел реальные данные, если они появятся в этой же базе позже.
 */
class DemoSeed extends Command
{
    protected $signature = 'demo:seed {--clear : Удалить ранее посеянные демо-данные вместо создания новых}';

    protected $description = 'Завести тестовых мастеров и заявки в разных статусах для ручного прогона бота';

    private const TAG = '[DEMO]';

    public function handle(): int
    {
        return $this->option('clear') ? $this->clear() : $this->seed();
    }

    private function seed(): int
    {
        $applianceTypes = ApplianceType::query()->get();
        $timeSlot = TimeSlot::query()->first();

        if ($applianceTypes->isEmpty() || ! $timeSlot) {
            $this->error('Справочники пусты — сначала php artisan db:seed --class=ReferenceDataSeeder.');

            return self::FAILURE;
        }

        $fridge = $applianceTypes->firstWhere('name', 'Холодильник') ?? $applianceTypes->first();
        $washer = $applianceTypes->firstWhere('name', 'Стиральная машина') ?? $applianceTypes->first();
        $owner = User::query()->where('role', UserRole::SUPERADMIN)->first();

        $masters = collect();

        foreach ([
            ['suffix' => 1, 'name' => 'Алексей', 'types' => [$fridge, $washer]],
            ['suffix' => 2, 'name' => 'Марина', 'types' => [$washer]],
            ['suffix' => 3, 'name' => 'Игорь', 'types' => [$fridge]],
        ] as $row) {
            $name = self::TAG.' Мастер '.$row['name'];

            $user = User::query()->firstOrCreate(
                ['telegram_user_id' => 990000000 + $row['suffix']],
                [
                    'role' => UserRole::MASTER,
                    'name' => $name,
                    'phone' => '+7900000'.str_pad((string) $row['suffix'], 4, '0', STR_PAD_LEFT),
                    'is_active' => true,
                ]
            );

            $master = Master::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'name' => $name,
                    'phone' => $user->phone,
                    'status' => MasterStatus::ACTIVE,
                    'is_active' => true,
                ]
            );

            $master->applianceTypes()->syncWithoutDetaching(collect($row['types'])->pluck('id'));

            $masters->push($master);
        }

        $this->info('Мастера: '.$masters->pluck('name')->implode(', '));

        [$masterA, $masterB, $masterC] = [$masters[0], $masters[1], $masters[2]];

        $rows = [
            ['status' => OrderStatus::NEW, 'master' => null, 'appliance' => $fridge, 'symptom' => 'Не морозит'],
            ['status' => OrderStatus::ASSIGNED, 'master' => $masterA, 'appliance' => $washer, 'symptom' => 'Не сливает воду'],
            ['status' => OrderStatus::ACCEPTED, 'master' => $masterA, 'appliance' => $fridge, 'symptom' => 'Течёт'],
            ['status' => OrderStatus::ON_THE_WAY, 'master' => $masterC, 'appliance' => $fridge, 'symptom' => 'Гудит'],
            ['status' => OrderStatus::DIAGNOSTICS, 'master' => $masterB, 'appliance' => $washer, 'symptom' => 'Не крутит барабан'],
            ['status' => OrderStatus::COMPLETED, 'master' => $masterA, 'appliance' => $fridge, 'symptom' => 'Не морозит', 'paid' => 'part'],
            ['status' => OrderStatus::PAID, 'master' => $masterB, 'appliance' => $washer, 'symptom' => 'Не включается', 'paid' => 'full'],
            ['status' => OrderStatus::MASTER_DECLINED, 'master' => null, 'appliance' => $washer, 'symptom' => 'Стучит при отжиме'],
        ];

        foreach ($rows as $i => $row) {
            $n = $i + 1;

            $priceFields = isset($row['paid'])
                ? ['final_price' => 3500, 'labor_price' => 2000, 'parts_sell_price' => 1500]
                : [];
            $paidFields = ($row['paid'] ?? null) === 'full' ? ['amount_paid' => 3500]
                : (($row['paid'] ?? null) === 'part' ? ['amount_paid' => 1500] : []);

            $order = Order::create([
                'customer_name' => self::TAG.' Клиент '.$n,
                'customer_phone' => '+7900001'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'appliance_type_id' => $row['appliance']->id,
                'symptom' => $row['symptom'],
                'address' => self::TAG.' ул. Тестовая, д. '.$n,
                'visit_date' => now()->addDay()->toDateString(),
                'time_slot_label' => $timeSlot->label,
                'time_slot_id' => $timeSlot->id,
                'status' => OrderStatus::NEW,
                'master_id' => $row['master']?->id,
                'created_by' => $owner?->id,
                'admin_comment' => self::TAG,
                ...$priceFields,
                ...$paidFields,
            ]);

            $order->update(['status' => $row['status']]);

            $this->line(sprintf('  %s — %s (%s)', $order->code(), $row['status']->label(), $order->customer_name));
        }

        $this->info(sprintf('Готово: %d мастеров, %d заявок.', $masters->count(), count($rows)));
        $this->warn('Удалить демо-данные: php artisan demo:seed --clear');

        return self::SUCCESS;
    }

    private function clear(): int
    {
        $orders = Order::query()->where('admin_comment', self::TAG)->get();
        $ordersCount = $orders->count();
        $orders->each->delete();

        $users = User::query()->where('name', 'like', self::TAG.'%')->get();
        $usersCount = $users->count();

        DB::transaction(function () use ($users): void {
            foreach ($users as $user) {
                $user->master?->delete();
                $user->delete();
            }
        });

        $this->info(sprintf('Удалено: %d заявок, %d мастеров.', $ordersCount, $usersCount));

        return self::SUCCESS;
    }
}
