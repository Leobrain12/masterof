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
        // Типовые проблемы по технике (ТЗ п.18.4). appliance_type_id = null — общая, подходит любой технике.
        Schema::create('symptoms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appliance_type_id')->nullable()->constrained('appliance_types')->nullOnDelete();
            $table->string('text');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Человекочитаемый номер заказа (ТЗ п.12: "number bigint unique") — отдельно от UUID id.
        // Значение выставляет App\Models\Order::booted() при создании (простой max()+1),
        // не БД-последовательность — так одна и та же миграция работает и на Postgres (прод),
        // и на sqlite (тесты), без двух разных путей генерации номера.
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('number')->unique();

            $table->uuid('lead_id')->nullable();
            $table->string('crm_id')->nullable();
            $table->string('source')->nullable();

            $table->string('customer_name');
            $table->string('customer_phone');

            $table->foreignId('appliance_type_id')->constrained('appliance_types');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('model')->nullable();
            $table->string('symptom');
            $table->text('description')->nullable();

            $table->string('address');
            $table->foreignId('geo_zone_id')->nullable()->constrained('geo_zones')->nullOnDelete();
            $table->decimal('address_lat', 10, 7)->nullable();
            $table->decimal('address_lon', 10, 7)->nullable();

            $table->date('visit_date');
            $table->string('time_slot_label');
            $table->foreignId('time_slot_id')->nullable()->constrained('time_slots')->nullOnDelete();

            $table->foreignUuid('master_id')->nullable()->constrained('masters')->nullOnDelete();
            $table->string('status')->default('NEW');

            $table->unsignedInteger('estimated_price')->nullable();
            $table->unsignedInteger('final_price')->nullable();
            $table->unsignedInteger('parts_sell_price')->nullable();
            $table->unsignedInteger('parts_cost')->nullable();
            $table->unsignedInteger('master_payout')->nullable();
            $table->unsignedInteger('amount_paid')->nullable();

            $table->text('admin_comment')->nullable();
            $table->text('master_comment')->nullable();

            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();
        });

        // Каждая смена статуса (ТЗ п.16) — не редактируется пользователем.
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Server-side состояние пошагового сценария создания заявки (ТЗ п.110-111).
        Schema::create('order_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('step');
            $table->json('payload')->default('{}');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_drafts');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('symptoms');
    }
};
