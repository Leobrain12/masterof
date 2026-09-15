<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ТЗ п.17.2/85: заказ может прийти через Internal API от CRM/сайта, где нет
 * telegram-пользователя, на которого можно сослаться (created_by ссылается
 * на users, а не на CRM-актора). OrderStatusHistory.changed_by_user_id уже
 * nullable по той же причине (см. OrderTransitionController::__invoke,
 * actor: null) — created_by приводится к тому же паттерну.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('created_by')->nullable(false)->change();
        });
    }
};
