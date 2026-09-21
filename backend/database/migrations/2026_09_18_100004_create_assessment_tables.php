<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A7 — Assessment Engine (fondasi tabel transaksi).
 *
 * Satu tabel `assessments` untuk semua jenis penilaian (keputusan arsitektur
 * blueprint: jenis penilaian adalah data, bukan skema), plus komponen, rubrik,
 * skor siswa, remedial, dan jejak revisi skor.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= PENILAIAN =================
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete(); // guru pengampu
            $table->foreignId('assessment_type_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('max_score', 5, 2)->default(100);
            $table->decimal('weight', 5, 2)->nullable(); // bobot spesifik (menggantikan bobot tipe bila diisi)
            $table->date('date')->nullable();
            $table->enum('status', ['draft', 'submitted', 'verified', 'finalized', 'revision_requested'])->default('draft');
            $table->foreignId('grading_formula_id')->nullable()->constrained()->nullOnDelete();
            $table->json('extra')->nullable(); // metadata fleksibel
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_class_id', 'subject_id', 'semester_id']);
            $table->index(['school_class_id', 'assessment_type_id']);
        });

        // ================= KOMPONEN PENILAIAN =================
        Schema::create('assessment_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('weight', 5, 2)->nullable();
            $table->decimal('max_score', 5, 2)->nullable();
            $table->timestamps();
        });

        // ================= RUBRIK PENILAIAN =================
        Schema::create('assessment_rubrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->string('criterion', 150);
            $table->string('indicator', 255)->nullable();
            $table->decimal('max_score', 5, 2)->nullable();
            $table->timestamps();
        });

        // ================= SKOR SISWA =================
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('grade', 5)->nullable();
            $table->string('predicate', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id']);
            $table->index('student_id');
        });

        // ================= REMEDIAL =================
        Schema::create('remedials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('score_before', 5, 2)->nullable();
            $table->decimal('score_after', 5, 2)->nullable();
            $table->date('date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id']);
        });

        // ================= JEJAK REVISI SKOR (AUDIT) =================
        Schema::create('score_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('score_id')->constrained()->cascadeOnDelete();
            $table->decimal('old_score', 5, 2)->nullable();
            $table->decimal('new_score', 5, 2)->nullable();
            $table->text('reason');
            $table->foreignId('revised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('score_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_revisions');
        Schema::dropIfExists('remedials');
        Schema::dropIfExists('scores');
        Schema::dropIfExists('assessment_rubrics');
        Schema::dropIfExists('assessment_components');
        Schema::dropIfExists('assessments');
    }
};