<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Итоговый отчёт о ремонте (ТЗ п.56) — в отличие от предварительных
     * failure_reason/work_needed/labor_price на Order (фаза 02, черновой расчёт
     * для согласования цены), это финальная, неизменяемая после подтверждения
     * запись. parts_cost nullable по букве ТЗ (MUST/SHOULD решает бизнес),
     * но бот-сценарий всё равно его запрашивает — см. vault/Фазы/Фаза 04.
     */
    public function up(): void
    {
        Schema::create('work_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('order_visits')->nullOnDelete();
            $table->foreignUuid('master_id')->constrained('masters');
            $table->text('failure_reason')->nullable();
            $table->text('work_description');
            $table->unsignedInteger('labor_price');
            $table->unsignedInteger('parts_sell_price');
            $table->unsignedInteger('parts_cost')->nullable();
            $table->unsignedInteger('final_price');
            $table->unsignedInteger('master_payout')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_reports');
    }
};
