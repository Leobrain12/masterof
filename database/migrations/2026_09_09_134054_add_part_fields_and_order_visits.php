<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Данные о нужной детали (ТЗ п.32.1). На Order, а не отдельной сущностью —
        // тот же подход, что и с failure_reason/work_needed в фазе 02: единственная
        // "текущая" проблема заказа, а не история нескольких деталей.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('part_name')->nullable()->after('work_needed');
            $table->string('part_article')->nullable()->after('part_name');
            $table->unsignedInteger('part_purchase_price')->nullable()->after('part_article');
            $table->text('part_comment')->nullable()->after('part_purchase_price');
        });

        // Повторные визиты в рамках одного заказа (ТЗ п.33-34) — не новый Order.
        // status здесь — только SCHEDULED/COMPLETED, не полноценная копия статусов
        // заказа: детальный ход конкретного визита по-прежнему отражает Order.status,
        // OrderVisit — это график визитов и история, а не вторая state machine.
        Schema::create('order_visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedInteger('visit_number');
            $table->date('visit_date');
            $table->string('time_slot_label');
            $table->foreignUuid('master_id')->nullable()->constrained('masters')->nullOnDelete();
            $table->string('status')->default('SCHEDULED');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_visits');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['part_name', 'part_article', 'part_purchase_price', 'part_comment']);
        });
    }
};
