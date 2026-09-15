<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Медиа теперь может прийти не только через бота (telegram_file_id обязателен,
 * есть кому приписать uploaded_by_user_id), но и через Internal API от CRM
 * (POST /api/v1/orders/{order}/media) — там нет ни Telegram file_id, ни
 * telegram-пользователя-загрузчика.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_media', function (Blueprint $table) {
            $table->string('telegram_file_id')->nullable()->change();
            $table->foreignUuid('uploaded_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_media', function (Blueprint $table) {
            $table->string('telegram_file_id')->nullable(false)->change();
            $table->foreignUuid('uploaded_by_user_id')->nullable(false)->change();
        });
    }
};
