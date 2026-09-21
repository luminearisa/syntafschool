<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A7 — Grading Engine (fondasi tabel konfigurasi).
 *
 * Skala nilai & grade, jenis penilaian, bobot penilaian, KKM/KKTP,
 * dan formula grading berversi.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= SKALA NILAI & GRADE =================
        Schema::create('grade_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('education_level_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('grade_scale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_scale_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_score', 5, 2);
            $table->decimal('max_score', 5, 2);
            $table->string('grade', 5); // A, B, C, D, E
            $table->string('predicate', 50)->nullable(); // Sangat Baik, Baik, ...
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['grade_scale_id', 'grade']);
        });

        // ================= JENIS PENILAIAN =================
        Schema::create('assessment_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique(); // tugas, quiz, uh, praktik, projek, pts, pas, sikap, tahfidz, portofolio
            $table->string('name', 100);
            $table->enum('category', ['pengetahuan', 'keterampilan', 'sikap'])->default('pengetahuan');
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ================= BOBOT PENILAIAN (per kelas per tahun) =================
        Schema::create('assessment_weights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_type_id')->constrained()->restrictOnDelete();
            $table->decimal('weight', 5, 2); // persen, mis. 20.00
            $table->timestamps();

            $table->unique(['school_class_id', 'academic_year_id', 'assessment_type_id'], 'assessment_weight_uniq');
        });

        // ================= KKM / KKTP =================
        Schema::create('passing_grade_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('education_level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('min_score', 5, 2)->default(75);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_id', 'academic_year_id']);
        });

        // ================= FORMULA GRADING (BERVERSI) =================
        Schema::create('grading_formulas', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->enum('formula_type', ['weighted_average', 'total', 'custom'])->default('weighted_average');
            $table->json('settings')->nullable(); // detail aturan pembulatan, dsb.
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('effective_academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_formulas');
        Schema::dropIfExists('passing_grade_rules');
        Schema::dropIfExists('assessment_weights');
        Schema::dropIfExists('assessment_types');
        Schema::dropIfExists('grade_scale_items');
        Schema::dropIfExists('grade_scales');
    }
};