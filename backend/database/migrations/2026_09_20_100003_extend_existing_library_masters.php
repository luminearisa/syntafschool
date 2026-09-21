<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perluasan master existing agar dapat dipakai modul Library.
 *
 * Publisher, Creator (person), dan Category sudah tersedia sebagai master
 * global di sistem ini. Sesuai spesifikasi §4, tabelnya **dipakai ulang**
 * (bukan dibuat `library_publishers` / `library_authors` / duplikat lain),
 * lalu dilengkapi kolom yang dibutuhkan katalog.
 *
 * Kolom lama dipertahankan apa adanya:
 * - `book_categories.is_active` tetap (dipakai seeder existing) dan menjadi
 *   sumber nilai `status` pada API.
 * - `publishers.code` & `authors.code` nullable karena baris lama belum punya kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->foreignId('library_id')->nullable()->after('id')->constrained('libraries')->cascadeOnDelete();
            $table->string('code', 50)->nullable()->after('library_id');
            $table->string('short_name', 100)->nullable()->after('name');
            $table->text('description')->nullable()->after('short_name');
            $table->foreignId('country_id')->nullable()->after('description')->constrained('library_countries')->nullOnDelete();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('website', 150)->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();

            $table->index(['library_id', 'status']);
            $table->index('code');
        });

        Schema::table('authors', function (Blueprint $table) {
            $table->foreignId('library_id')->nullable()->after('id')->constrained('libraries')->cascadeOnDelete();
            $table->string('code', 50)->nullable()->after('library_id');
            $table->foreignId('country_id')->nullable()->after('name')->constrained('library_countries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();

            $table->index(['library_id', 'status']);
            $table->index('code');
        });

        Schema::table('book_categories', function (Blueprint $table) {
            $table->foreignId('library_id')->nullable()->after('id')->constrained('libraries')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->after('library_id')->constrained('book_categories')->nullOnDelete();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();

            $table->index(['library_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('book_categories', function (Blueprint $table) {
            $table->dropForeign(['library_id']);
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['library_id', 'parent_id', 'description', 'sort_order', 'deleted_at']);
        });

        Schema::table('authors', function (Blueprint $table) {
            $table->dropForeign(['library_id']);
            $table->dropForeign(['country_id']);
            $table->dropColumn(['library_id', 'code', 'country_id', 'notes', 'status', 'sort_order', 'deleted_at']);
        });

        Schema::table('publishers', function (Blueprint $table) {
            $table->dropForeign(['library_id']);
            $table->dropForeign(['country_id']);
            $table->dropColumn([
                'library_id', 'code', 'short_name', 'description', 'country_id', 'city', 'province',
                'postal_code', 'phone', 'email', 'website', 'contact_person', 'status', 'notes',
                'sort_order', 'deleted_at',
            ]);
        });
    }
};