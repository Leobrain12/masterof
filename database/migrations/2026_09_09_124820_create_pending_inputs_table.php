<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Небольшой переиспользуемый примитив: "жду один свободный текст от этого
     * пользователя по такому-то поводу" (сейчас — причина отказа мастера "Другое",
     * ТЗ п.22). Отдельно от order_drafts: там — весь многошаговый сценарий создания
     * заявки, здесь — разовое ожидание одного ответа вне какого-либо сценария.
     */
    public function up(): void
    {
        Schema::create('pending_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('kind');
            $table->json('payload')->default('{}');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_inputs');
    }
};
