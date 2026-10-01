<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog aset: kategori berjenjang, jenis, dan atribut yang dapat dikonfigurasi.
 *
 * `asset_subcategories` pada rancangan awal tidak dibuat sebagai tabel terpisah:
 * hierarkinya sudah dibawa `parent_id`, dan dua sumber kebenaran untuk satu pohon
 * adalah cara paling mudah membuat laporan kategori tidak pernah cocok.
 *
 * `asset_attribute_values` sengaja berada di migrasi register (setelah tabel
 * `assets`) karena ia merujuk ke sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('asset_categories')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('kind', 24)->default('ASSET');
            $table->string('depreciation_method', 32)->nullable();
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->string('capitalization_threshold', 32)->nullable();
            $table->json('maintenance_policy')->nullable();
            $table->unsignedSmallInteger('inspection_frequency_days')->nullable();
            $table->string('default_criticality', 24)->default('NORMAL');
            $table->boolean('is_consumable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['school_id', 'code']);
        });
        Schema::create('asset_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('category_id')->constrained('asset_categories')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->json('specification_schema')->nullable();
            $table->unsignedSmallInteger('default_useful_life_months')->nullable();
            $table->string('default_criticality', 24)->default('NORMAL');
            $table->string('default_depreciation_method', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['school_id', 'code']);
        });
        Schema::create('asset_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_type_id')->constrained('asset_types')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('label', 120);
            $table->string('data_type', 24)->default('string');
            $table->string('unit', 24)->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['asset_type_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_attributes');
        Schema::dropIfExists('asset_types');
        Schema::dropIfExists('asset_categories');
    }
};
