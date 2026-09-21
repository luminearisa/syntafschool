<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A5 — Curriculum Engine (fondasi tabel).
 *
 * Tabel untuk struktur kurikulum: kelompok mapel, program/peminatan,
 * struktur kurikulum berversi, item struktur (alokasi JP), dan prasyarat mapel.
 *
 * Struktur kurikulum bersifat berversi per (kurikulum, jenjang). Mengubah
 * struktur tidak boleh mengubah kelas yang sudah berjalan; penerapan ke kelas
 * dilakukan secara eksplisit lewat `apply-curriculum`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= KELOMPOK MATA PELAJARAN =================
        Schema::create('subject_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ================= PROGRAM / PEMINATAN =================
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->foreignId('education_level_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ================= STRUKTUR KURIKULUM (BERVERSI) =================
        Schema::create('curriculum_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained()->restrictOnDelete();
            $table->foreignId('education_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Satu versi hanya boleh ada sekali per (kurikulum, jenjang).
            $table->unique(
                ['curriculum_id', 'education_level_id', 'version'],
                'curriculum_structure_version_unique',
            );
        });

        // ================= ITEM STRUKTUR KURIKULUM =================
        Schema::create('curriculum_structure_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_structure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_group_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('semester'); // 1 = Ganjil, 2 = Genap
            $table->unsignedSmallInteger('weekly_hours'); // JP per pekan
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Satu mapel tidak boleh dobel dalam struktur yang sama pada semester yang sama.
            $table->unique(
                ['curriculum_structure_id', 'subject_id', 'semester'],
                'curriculum_structure_item_unique',
            );
        });

        // ================= PRASYARAT MATA PELAJARAN =================
        Schema::create('subject_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['subject_id', 'prerequisite_subject_id'],
                'subject_prerequisite_unique',
            );
        });

        // ================= PEMINATAN SISWA =================
        // Program/peminatan yang diambil siswa (opsional).
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
        });

        // Penanda asal kelas: baris `class_subjects` yang lahir dari apply-curriculum.
        // Dipakai untuk mengunci penghapusan struktur yang sudah dipakai kelas.
        Schema::table('class_subjects', function (Blueprint $table) {
            $table->foreignId('curriculum_structure_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('class_subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('curriculum_structure_id');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_id');
        });

        Schema::dropIfExists('subject_prerequisites');
        Schema::dropIfExists('curriculum_structure_items');
        Schema::dropIfExists('curriculum_structures');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('subject_groups');
    }
};
