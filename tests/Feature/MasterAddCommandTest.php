<?php

namespace Tests\Feature;

use App\Enums\MasterStatus;
use App\Enums\UserRole;
use App\Models\ApplianceType;
use App\Models\Brand;
use App\Models\GeoZone;
use App\Models\Master;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterAddCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_creates_master_with_specialization(): void
    {
        $this->artisan('master:add', [
            'telegram_id' => 555111222,
            'name' => 'Иван Мастеров',
            'phone' => '+79990001122',
            '--appliance' => ['Холодильник'],
            '--brand' => ['Liebherr'],
            '--zone' => ['Одинцово'],
        ])->assertExitCode(0);

        $user = User::query()->where('telegram_user_id', 555111222)->firstOrFail();
        $this->assertSame(UserRole::MASTER, $user->role);
        $this->assertTrue($user->is_active);

        $master = Master::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(MasterStatus::ACTIVE, $master->status);
        $this->assertTrue($master->is_active);

        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $liebherr = Brand::where('name', 'Liebherr')->firstOrFail();
        $odintsovo = GeoZone::where('name', 'Одинцово')->firstOrFail();

        $this->assertTrue($master->applianceTypes->contains($fridge));
        $this->assertTrue($master->brands->contains($liebherr));
        $this->assertTrue($master->geoZones->contains($odintsovo));
    }

    public function test_appliance_is_required(): void
    {
        $this->artisan('master:add', [
            'telegram_id' => 555111333,
            'name' => 'Без специализации',
            'phone' => '+79990001133',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['telegram_user_id' => 555111333]);
    }

    public function test_rejects_unknown_appliance_name(): void
    {
        $this->artisan('master:add', [
            'telegram_id' => 555111444,
            'name' => 'Опечатка',
            'phone' => '+79990001144',
            '--appliance' => ['Холодильникъ'],
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['telegram_user_id' => 555111444]);
    }

    public function test_rejects_duplicate_telegram_id(): void
    {
        $this->artisan('master:add', [
            'telegram_id' => 555111555,
            'name' => 'Первый',
            'phone' => '+79990001155',
            '--appliance' => ['Холодильник'],
        ])->assertExitCode(0);

        $this->artisan('master:add', [
            'telegram_id' => 555111555,
            'name' => 'Второй',
            'phone' => '+79990001166',
            '--appliance' => ['Холодильник'],
        ])->assertExitCode(1);

        $this->assertSame(1, User::query()->where('telegram_user_id', 555111555)->count());
    }

    public function test_works_without_brand_or_zone(): void
    {
        $this->artisan('master:add', [
            'telegram_id' => 555111666,
            'name' => 'Универсал',
            'phone' => '+79990001177',
            '--appliance' => ['Холодильник'],
        ])->assertExitCode(0);

        $master = Master::query()
            ->whereHas('user', fn ($q) => $q->where('telegram_user_id', 555111666))
            ->firstOrFail();

        $this->assertCount(0, $master->brands);
        $this->assertCount(0, $master->geoZones);
    }
}
