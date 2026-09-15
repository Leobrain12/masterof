<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ТЗ п.64.2: связь гарантийного обращения с исходным заказом. Гарантийный
 * заказ — отдельная строка в orders (не правка исходной), поэтому обычный
 * FK, не JSON-поле. nullOnDelete — если по каким-то причинам исходный заказ
 * удалят, гарантийный не должен исчезнуть вместе с ним, только потерять ссылку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('warranty_parent_order_id')->nullable()->after('crm_id')
                ->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['warranty_parent_order_id']);
            $table->dropColumn('warranty_parent_order_id');
        });
    }
};
