<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahfiz T1 — Referensi Al-Quran & konfigurasi program.
 *
 * Bagian pertama modul Tahfiz. Berisi *single source of truth* yang dipakai
 * seluruh perhitungan: data surah (mushaf Utsmani 604 halaman) dan konfigurasi
 * yang sengaja dibuat sebagai data — bukan kode — agar setiap aturan bisa
 * diubah tanpa merilis ulang aplikasi.
 *
 * Aturan yang dikonfigurasi di sini, bukan ditulis keras di kode:
 * - program & level beserta target dan kehadiran minimumnya
 * - rubrik penilaian (tahsin/tilawah/hafalan) beserta deskripsi tiap skor
 * - taksonomi kesalahan hafalan (dipakai untuk analitik)
 * - item mutabaah harian
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= REFERENSI AL-QURAN =================
        // Sumber kebenaran struktur mushaf. Dipakai progress engine & ujian
        // acak ayat. Tidak pernah diubah oleh pengguna.
        Schema::create('quran_surahs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('number')->unique(); // 1..114 sesuai mushaf
            $table->string('name', 50);          // An-Naba
            $table->string('arabic_name', 50);   // النَّبَأ
            $table->string('translation', 100)->nullable();
            $table->unsignedSmallInteger('ayah_count');
            $table->unsignedSmallInteger('page_start'); // halaman awal di mushaf Utsmani
            $table->unsignedSmallInteger('page_end');
            $table->unsignedTinyInteger('juz_start');
            $table->unsignedTinyInteger('juz_end');
            $table->enum('revelation_place', ['makkah', 'madinah']);
            $table->timestamps();

            $table->index(['juz_start', 'juz_end']);
            $table->index('page_start');
        });

        // ================= PROGRAM TAHFIZ =================
        Schema::create('tahfiz_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            // Peserta yang diperbolehkan. null = semua jenis peserta.
            $table->enum('participant_type', ['student', 'employee', 'guardian', 'public', 'alumni', 'other'])->nullable();
            $table->unsignedSmallInteger('duration_value')->nullable();
            $table->enum('duration_unit', ['day', 'week', 'month', 'semester', 'year'])->nullable();
            // Bentuk target program: juz / surah / ayat / halaman / tidak ada target kuantitatif.
            $table->enum('target_type', ['juz', 'surah', 'ayat', 'page', 'none'])->default('none');
            $table->string('target_value', 100)->nullable(); // mis. "30", "2", "An-Naba"
            $table->unsignedSmallInteger('minimum_attendance')->default(0); // persen
            // Skema penilaian: json agar setiap program bebas menentukan komponennya.
            $table->json('assessment_scheme')->nullable();
            $table->boolean('requires_placement_test')->default(false);
            $table->boolean('self_registration')->default(false); // peserta umum boleh mendaftar sendiri
            $table->enum('status', ['draft', 'active', 'archived'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // ================= LEVEL TAHFIZ =================
        Schema::create('tahfiz_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->nullable()->constrained('tahfiz_programs')->nullOnDelete();
            $table->string('name', 100);
            $table->string('code', 30)->nullable();
            $table->unsignedSmallInteger('order')->default(0); // urut naik: Pemula → Tahsin → Juz 30 …
            $table->unsignedTinyInteger('juz_start')->nullable();
            $table->unsignedTinyInteger('juz_end')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('program_id');
        });

        // ================= RUBRIK PENILAIAN =================
        // Rubrik adalah data: admin bisa membuat rubrik sendiri untuk tahsin,
        // tilawah, placement test, maupun ujian.
        Schema::create('tahfiz_rubrics', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->enum('scope', ['tahsin', 'tilawah', 'hafalan', 'placement', 'exam', 'tasmi']);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('max_score')->default(5);
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope', 'status']);
        });

        Schema::create('tahfiz_rubric_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained('tahfiz_rubrics')->cascadeOnDelete();
            $table->string('criterion', 100); // Makhraj, Tajwid, Kelancaran, Tartil …
            $table->decimal('weight', 5, 2)->default(1); // bobot terhadap skor total
            $table->unsignedTinyInteger('order')->default(0);
            // Deskripsi per tingkat skor: { "1": "…", "5": "…" }
            $table->json('level_descriptions')->nullable();
            $table->timestamps();

            $table->index('rubric_id');
        });

        // ================= TAKSONOMI KESALAHAN =================
        // Jenis kesalahan hafalan diberi tipe sendiri agar bisa dianalisis:
        // "lupa ayat", "salah sambung", "salah lafadz", "tajwid", "makhraj" …
        Schema::create('tahfiz_mistake_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('label', 100);
            $table->enum('category', ['memorization', 'pronunciation', 'tajwid', 'reading', 'other'])->default('memorization');
            // Bobot dampak terhadap penurunan mutqin (analitik).
            $table->decimal('severity_weight', 4, 2)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category');
        });

        // ================= ITEM MUTABAAH HARIAN =================
        // Item mutabaah tidak ditulis keras di kode; admin mendefinisikannya.
        Schema::create('tahfiz_mutabaah_items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('label', 100); // Tilawah 1 juz, Murojaah, Shalat berjamaah …
            $table->enum('category', ['quran', 'worship', 'activity', 'other'])->default('quran');
            $table->enum('value_type', ['boolean', 'count'])->default('boolean');
            $table->string('unit', 20)->nullable(); // halaman / juz / kali
            $table->unsignedSmallInteger('target_value')->nullable();
            $table->unsignedTinyInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ================= PROFIL INSTRUKTUR =================
        // Guru Tahfiz adalah pegawai (employees). Tabel ini menyimpan
        // kekhususan mengajar mereka, bukan meniru data pegawai.
        Schema::create('tahfiz_instructors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('role', ['instructor', 'assistant', 'musyrif', 'musyrifah'])->default('instructor');
            // SQLite tidak punya tipe SET; kekhususan disimpan sebagai larik json.
            $table->json('specialization')->nullable(); // [tahsin, tahfiz, tilawah, tasmi, murojaah]
            $table->string('certificate', 150)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'role']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tahfiz_instructors');
        Schema::dropIfExists('tahfiz_mutabaah_items');
        Schema::dropIfExists('tahfiz_mistake_types');
        Schema::dropIfExists('tahfiz_rubric_items');
        Schema::dropIfExists('tahfiz_rubrics');
        Schema::dropIfExists('tahfiz_levels');
        Schema::dropIfExists('tahfiz_programs');
        Schema::dropIfExists('quran_surahs');
    }
};
