<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master / reference data modul Library (Step 2).
 *
 * Dua kelompok:
 * - **Global** (tanpa `library_id`): material type, language, country,
 *   creator role — berlaku untuk semua perpustakaan.
 * - **Ber-scope** (`library_id` nullable): NULL = global, X = hanya milik
 *   perpustakaan X (spesifikasi §22).
 *
 * Entity yang sudah tersedia secara global TIDAK diduplikasi di sini:
 * publisher, creator (person), dan category memakai tabel existing yang
 * diperluas pada migrasi berikutnya.
 *
 * Catatan SQLite: indeks unik `(library_id, code)` tidak menahan duplikasi
 * untuk baris global karena NULL dianggap unik oleh SQLite — keunikan kode
 * pada scope global ditegakkan di lapisan validasi (lihat LibraryMasterService).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= GLOBAL (tanpa library_id) =================

        Schema::create('library_material_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('library_languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('native_name', 100)->nullable();
            $table->string('iso_code', 10)->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('library_countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->string('iso_code', 3)->nullable();
            $table->string('phone_code', 6)->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('library_creator_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // ================= BER-SCOPE (library_id nullable) =================

        Schema::create('library_collection_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            // Jenis bibliografi (buku, majalah) terpisah dari bentuk medianya.
            $table->boolean('is_digital')->default(false);
            $table->boolean('is_physical')->default(true);
            $table->string('icon', 50)->nullable();
            $table->string('color', 20)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'status']);
        });

        Schema::create('library_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('library_subjects')->nullOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'parent_id']);
        });

        Schema::create('library_classification_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'status']);
        });

        Schema::create('library_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            // DDC/LCC/Custom bukan hardcode — sistem klasifikasi adalah data.
            $table->foreignId('system_id')->constrained('library_classification_systems')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('library_classifications')->nullOnDelete();
            $table->string('code', 50);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('level')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['system_id', 'code']);
            $table->index(['library_id', 'status']);
            $table->index(['system_id', 'parent_id']);
        });

        Schema::create('library_genres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('library_genres')->nullOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'parent_id']);
        });

        Schema::create('library_audience_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'status']);
        });

        Schema::create('library_acquisition_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'status']);
        });

        Schema::create('library_collection_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            // Dipakai inventory nanti untuk menilai kelayakan fisik.
            $table->boolean('is_borrowable')->default(true);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'status']);
        });

        Schema::create('library_collection_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_available')->default(true);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'status']);
        });

        Schema::create('library_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->foreignId('publisher_id')->nullable()->constrained('publishers')->nullOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'status']);
        });

        Schema::create('library_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_tags');
        Schema::dropIfExists('library_series');
        Schema::dropIfExists('library_collection_statuses');
        Schema::dropIfExists('library_collection_conditions');
        Schema::dropIfExists('library_acquisition_sources');
        Schema::dropIfExists('library_audience_types');
        Schema::dropIfExists('library_genres');
        Schema::dropIfExists('library_classifications');
        Schema::dropIfExists('library_classification_systems');
        Schema::dropIfExists('library_subjects');
        Schema::dropIfExists('library_collection_types');
        Schema::dropIfExists('library_creator_roles');
        Schema::dropIfExists('library_countries');
        Schema::dropIfExists('library_languages');
        Schema::dropIfExists('library_material_types');
    }
};