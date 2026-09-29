<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Learning Outcome, pemetaan aktivitas, capaian siswa, Competency
 * Framework, dan evidence kompetensi (§26–§31, §50).
 *
 * Nilai dan competency sengaja dipisah: competency dinilai guru dengan bukti
 * (evidence), tidak diturunkan otomatis dari angka (§30).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= LEARNING OUTCOME (§26) =================
        Schema::create('lms_learning_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('code', 40)->nullable();                 // LO1, LO2, ...
            $table->string('statement', 500);                        // rumusan capaian
            $table->text('description')->nullable();
            $table->string('category', 40)->nullable();              // pengetahuan/keterampilan/sikap
            $table->json('achievement_thresholds')->nullable();      // {"developing":60,"achieved":75,"mastered":90}
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'sort_order']);
        });

        Schema::create('lms_activity_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('lms_activities')->cascadeOnDelete();
            $table->foreignId('learning_outcome_id')->constrained('lms_learning_outcomes')->cascadeOnDelete();
            $table->decimal('weight', 6, 2)->default(1);
            $table->unsignedBigInteger('mapped_by')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'learning_outcome_id'], 'lms_activity_outcome_uniq');
        });

        Schema::create('lms_student_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_outcome_id')->constrained('lms_learning_outcomes')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('status', 20)->default('NOT_ASSESSED');   // OutcomeAchievement
            $table->decimal('score', 8, 2)->nullable();
            $table->unsignedInteger('evidence_count')->default(0);
            $table->json('calculation')->nullable();                 // rincian per aktivitas
            $table->unsignedBigInteger('assessed_by')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();

            $table->unique(['learning_outcome_id', 'user_id'], 'lms_student_outcome_uniq');
            $table->index(['course_id', 'user_id']);
        });

        // ================= COMPETENCY (§29–§31) =================
        Schema::create('lms_competency_frameworks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->nullable();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('lms_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('framework_id')->constrained('lms_competency_frameworks')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('lms_competencies')->cascadeOnDelete();
            $table->string('code', 40)->nullable();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->index(['framework_id', 'parent_id']);
        });

        Schema::create('lms_course_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('lms_competencies')->cascadeOnDelete();
            $table->boolean('is_required')->default(true);
            $table->decimal('weight', 6, 2)->default(1);
            $table->unsignedBigInteger('mapped_by')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'competency_id'], 'lms_course_competency_uniq');
        });

        Schema::create('lms_activity_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('lms_activities')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('lms_competencies')->cascadeOnDelete();
            $table->decimal('weight', 6, 2)->default(1);
            $table->unsignedBigInteger('mapped_by')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'competency_id'], 'lms_activity_competency_uniq');
        });

        Schema::create('lms_student_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competency_id')->constrained('lms_competencies')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('status', 20)->default('NOT_STARTED');    // CompetencyStatus
            $table->decimal('score', 8, 2)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('assessed_by')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();

            $table->unique(['competency_id', 'user_id', 'course_id'], 'lms_student_competency_uniq');
        });

        Schema::create('lms_competency_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competency_id')->constrained('lms_competencies')->cascadeOnDelete();
            $table->foreignId('student_competency_id')->nullable()->constrained('lms_student_competencies')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('lms_activities')->cascadeOnDelete();
            $table->foreignId('grade_item_id')->nullable()->constrained('lms_grade_items')->cascadeOnDelete();
            $table->unsignedBigInteger('assessment_result_id')->nullable();
            $table->unsignedBigInteger('rubric_assessment_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('evidence_type', 20)->default('MANUAL');  // CompetencyEvidenceType
            $table->string('title', 180)->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index(['competency_id', 'user_id']);
            $table->index(['course_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_competency_evidence');
        Schema::dropIfExists('lms_student_competencies');
        Schema::dropIfExists('lms_activity_competencies');
        Schema::dropIfExists('lms_course_competencies');
        Schema::dropIfExists('lms_competencies');
        Schema::dropIfExists('lms_competency_frameworks');
        Schema::dropIfExists('lms_student_outcomes');
        Schema::dropIfExists('lms_activity_outcomes');
        Schema::dropIfExists('lms_learning_outcomes');
    }
};
