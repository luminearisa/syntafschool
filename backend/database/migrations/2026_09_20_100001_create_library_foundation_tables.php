<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fondasi modul Library / Perpustakaan (Step 1).
 *
 * Library adalah entitas tersendiri, bukan singleton: satu sekolah dapat
 * memiliki beberapa perpustakaan (mis. SMP, SMA, umum). Karena itu seluruh
 * tabel konfigurasi di bawah ini ber-scope `library_id`.
 *
 * Catatan integrasi:
 * - `settings` yang sudah ada bersifat global (kolom `key` unik tanpa pemilik),
 *   sehingga tidak dapat menyimpan konfigurasi per-perpustakaan → `library_settings`.
 * - `document_sequences` yang sudah ada juga global dan tanpa padding/nomor awal
 *   → `library_numbering_configs` (lihat NumberingService).
 * - Audit memakai paket `spatie/laravel-activitylog` yang sudah terpasang,
 *   bukan tabel baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('timezone', 50)->default('Asia/Jakarta');
            $table->string('logo_path')->nullable();
            $table->enum('status', ['draft', 'active', 'inactive', 'archived'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'school_id']);
        });

        Schema::create('library_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            // Disimpan sebagai teks; di-cast sesuai kolom `type`.
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string|integer|boolean|decimal|json
            $table->string('group', 50)->default('general');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['library_id', 'key']);
        });

        Schema::create('library_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            // Hierarki lokasi (gedung → lantai → ruang) memakai self reference.
            $table->foreignId('parent_id')->nullable()->constrained('library_locations')->nullOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('type', 30)->default('room'); // building|floor|room|reading_area|storage|service_desk|other
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'parent_id']);
        });

        Schema::create('library_operating_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0=Ahad … 6=Sabtu
            $table->boolean('is_open')->default(true);
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->timestamps();

            // Satu hari boleh punya beberapa baris (mis. 08:00–12:00 dan 13:00–16:00).
            $table->index(['library_id', 'day_of_week']);
        });

        Schema::create('library_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Jam opsional: boleh menutup sebagian hari saja.
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason', 200);
            $table->string('type', 30)->default('other'); // national_holiday|school_holiday|maintenance|school_event|other
            $table->enum('status', ['active', 'cancelled'])->default('active');
            $table->timestamps();

            $table->index(['library_id', 'date']);
        });

        Schema::create('library_numbering_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50); // member|loan|item|reservation|purchase|certificate
            $table->string('format', 100); // mis. LIB-MBR-{YYYY}-{SEQ}
            $table->string('prefix', 20)->nullable();
            $table->unsignedTinyInteger('padding')->default(3);
            $table->enum('reset_period', ['never', 'year', 'month'])->default('never');
            $table->unsignedInteger('start_number')->default(1);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamp('last_reset_at')->nullable();
            $table->timestamps();

            $table->unique(['library_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_numbering_configs');
        Schema::dropIfExists('library_closures');
        Schema::dropIfExists('library_operating_hours');
        Schema::dropIfExists('library_locations');
        Schema::dropIfExists('library_settings');
        Schema::dropIfExists('libraries');
    }
};