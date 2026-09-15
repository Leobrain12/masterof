<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Фото/видео заказа (ТЗ п.49) — универсальная сущность вместо отдельной
     * WorkReportPhoto, как и требует ТЗ. work_report_id/visit_id nullable:
     * медиа с диагностики или детали ещё не привязано к конкретному отчёту.
     */
    public function up(): void
    {
        Schema::create('order_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('order_visits')->nullOnDelete();
            $table->foreignUuid('work_report_id')->nullable()->constrained('work_reports')->nullOnDelete();
            $table->foreignUuid('uploaded_by_user_id')->constrained('users');
            $table->string('media_type');
            $table->string('stage');
            $table->string('telegram_file_id');
            $table->string('telegram_file_unique_id')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('original_file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('caption')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_media');
    }
};
