<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Идемпотентность апдейтов Telegram (ТЗ п.90) — update_id как PK: повторная
     * доставка того же апдейта (сеть, ретраи, повтор через getUpdates после
     * несвоевременного ack) наткнётся на уникальность и будет проигнорирована
     * до того, как дойдёт до бизнес-логики.
     */
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->unsignedBigInteger('update_id')->primary();
            $table->timestamp('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
