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
        // Предварительные данные согласования цены (ТЗ п.28-29). Отдельный WorkReport
        // с итоговыми work_description/labor_price и т.д. появится в фазе 04 — эти поля
        // на Order намеренно временные, WorkReport при создании возьмёт их как черновик.
        Schema::table('orders', function (Blueprint $table) {
            $table->text('failure_reason')->nullable()->after('symptom');
            $table->text('work_needed')->nullable()->after('failure_reason');
            $table->unsignedInteger('labor_price')->nullable()->after('estimated_price');
        });

        // Недозвон (ТЗ п.36-38).
        Schema::create('contact_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestamp('attempted_at')->useCurrent();
            $table->string('result');
            $table->text('comment')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_attempts');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['failure_reason', 'work_needed', 'labor_price']);
        });
    }
};
