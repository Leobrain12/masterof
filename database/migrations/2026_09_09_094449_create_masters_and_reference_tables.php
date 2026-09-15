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
        // --- Справочники (ТЗ п.18.1, 18.2, 9, 18.10) ---

        Schema::create('appliance_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('geo_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('time_slots', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // --- Master (ТЗ п.8) ---

        Schema::create('masters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->string('commission_type')->nullable();
            $table->decimal('commission_value', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Master связан с ApplianceType, Brand, GeoArea (ТЗ п.9)
        Schema::create('master_appliance_type', function (Blueprint $table) {
            $table->foreignUuid('master_id')->constrained('masters')->cascadeOnDelete();
            $table->foreignId('appliance_type_id')->constrained('appliance_types')->cascadeOnDelete();
            $table->primary(['master_id', 'appliance_type_id']);
        });

        Schema::create('master_brand', function (Blueprint $table) {
            $table->foreignUuid('master_id')->constrained('masters')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->primary(['master_id', 'brand_id']);
        });

        Schema::create('master_geo_zone', function (Blueprint $table) {
            $table->foreignUuid('master_id')->constrained('masters')->cascadeOnDelete();
            $table->foreignId('geo_zone_id')->constrained('geo_zones')->cascadeOnDelete();
            $table->primary(['master_id', 'geo_zone_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_geo_zone');
        Schema::dropIfExists('master_brand');
        Schema::dropIfExists('master_appliance_type');
        Schema::dropIfExists('masters');
        Schema::dropIfExists('time_slots');
        Schema::dropIfExists('geo_zones');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('appliance_types');
    }
};
