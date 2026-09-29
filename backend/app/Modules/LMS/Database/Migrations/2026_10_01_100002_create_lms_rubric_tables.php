<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Rubric, criterion, level, dan hasil penilaian rubrik
 * (§22–§25, §58).
 *
 * Rubric boleh berdiri sendiri sebagai template institusi (`course_id` null)
 * sehingga guru dapat memakai ulang tanpa menyalin isi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_rubrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('grading_method', 20)->default('POINTS'); // RubricGradingMethod
            $table->decimal('maximum_score', 6, 2)->default(100);
            $table->string('status', 20)->default('ACTIVE');          // RubricStatus
            $table->boolean('is_template')->default(false);
            $table->boolean('is_shared')->default(true);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'status']);
            $table->index(['is_template', 'status']);
        });

        Schema::create('lms_rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained('lms_rubrics')->cascadeOnDelete();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->decimal('weight', 6, 2)->default(0);
            $table->decimal('maximum_score', 6, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['rubric_id', 'sort_order']);
        });

        Schema::create('lms_rubric_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('criterion_id')->constrained('lms_rubric_criteria')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('score', 6, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['criterion_id', 'sort_order']);
        });

        Schema::create('lms_rubric_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained('lms_rubrics')->cascadeOnDelete();
            $table->foreignId('grade_item_id')->nullable()->constrained('lms_grade_items')->cascadeOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('lms_activities')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('submission_id')->nullable();
            $table->string('status', 20)->default('DRAFT');           // RubricAssessmentStatus
            $table->decimal('total_score', 8, 2)->default(0);
            $table->decimal('maximum_score', 8, 2)->default(0);
            $table->decimal('normalized_score', 8, 4)->nullable();
            $table->text('feedback')->nullable();
            $table->text('feedback_private')->nullable();
            $table->json('feedback_meta')->nullable();
            $table->unsignedBigInteger('assessed_by')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();

            $table->index(['grade_item_id', 'user_id']);
            $table->index(['activity_id', 'user_id']);
        });

        Schema::create('lms_rubric_assessment_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_assessment_id')->constrained('lms_rubric_assessments')->cascadeOnDelete();
            $table->foreignId('criterion_id')->constrained('lms_rubric_criteria')->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('lms_rubric_levels')->nullOnDelete();
            $table->decimal('score', 8, 2)->default(0);
            $table->string('comment', 255)->nullable();
            $table->timestamps();

            $table->unique(['rubric_assessment_id', 'criterion_id'], 'lms_rubric_assessment_criterion_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_rubric_assessment_scores');
        Schema::dropIfExists('lms_rubric_assessments');
        Schema::dropIfExists('lms_rubric_levels');
        Schema::dropIfExists('lms_rubric_criteria');
        Schema::dropIfExists('lms_rubrics');
    }
};
