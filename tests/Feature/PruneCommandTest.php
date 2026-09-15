<?php

namespace Tests\Feature;

use App\Enums\OrderDraftStep;
use App\Models\OrderDraft;
use App\Models\PendingInput;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_prune_removes_expired_pending_inputs_and_drafts_and_old_updates(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        // user_id уникален в pending_inputs — два pending-а не могут висеть
        // на одном пользователе одновременно, поэтому берём разных.
        $expiredInput = PendingInput::query()->create([
            'user_id' => $user->id,
            'kind' => 'work_report',
            'payload' => [],
            'expires_at' => now()->subMinute(),
        ]);

        $freshInput = PendingInput::query()->create([
            'user_id' => $otherUser->id,
            'kind' => 'work_report',
            'payload' => [],
            'expires_at' => now()->addMinutes(30),
        ]);

        // created_by_user_id тоже уникален в order_drafts — та же причина.
        $expiredDraft = OrderDraft::query()->create([
            'created_by_user_id' => $user->id,
            'step' => OrderDraftStep::CUSTOMER_NAME,
            'payload' => [],
            'expires_at' => now()->subMinute(),
        ]);

        $freshDraft = OrderDraft::query()->create([
            'created_by_user_id' => $otherUser->id,
            'step' => OrderDraftStep::CUSTOMER_NAME,
            'payload' => [],
            'expires_at' => now()->addMinutes(30),
        ]);

        TelegramUpdate::query()->create(['update_id' => 111, 'processed_at' => now()->subDays(8)]);
        TelegramUpdate::query()->create(['update_id' => 222, 'processed_at' => now()->subDays(6)]);

        $this->artisan('model:prune')->assertExitCode(0);

        $this->assertModelMissing($expiredInput);
        $this->assertModelExists($freshInput);

        $this->assertModelMissing($expiredDraft);
        $this->assertModelExists($freshDraft);

        $this->assertDatabaseMissing('telegram_updates', ['update_id' => 111]);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 222]);
    }
}
