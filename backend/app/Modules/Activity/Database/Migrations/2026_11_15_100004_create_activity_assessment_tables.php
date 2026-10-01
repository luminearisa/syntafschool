<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rubrik, penilaian, bukti keterampilan, progres, dan penyelesaian aktivitas.
 *
 * Penilaian aktivitas tidak menggantikan penilaian akademik: modul ini menghitung
 * capaian perkembangan (skill, performa, partisipasi) dan menyimpannya terpisah,
 * lalu menyodorkan ringkasannya ke SIAKAD/rapor lewat kontrak akademik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_rubrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->nullable()->constrained('activity_programs')->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 24)->default('SKILL');
            $table->json('scale')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('activity_rubric_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained('activity_rubrics')->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('weight', 5, 2)->default(1);
            $table->decimal('max_score', 6, 2)->default(4);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['rubric_id', 'code'], 'activity_rubric_items_unique');
        });

        Schema::create('activity_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('activity_periods')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('activity_sessions')->nullOnDelete();
            $table->foreignId('rubric_id')->nullable()->constrained('activity_rubrics')->nullOnDelete();
            $table->string('title');
            $table->string('type', 24)->default('SKILL'); // SKILL|PERFORMANCE|PARTICIPATION|PROJECT|COMPETENCY|BEHAVIOR
            $table->text('description')->nullable();
            $table->decimal('max_score', 8, 2)->default(100);
            $table->decimal('weight', 5, 2)->default(1);
            $table->boolean('is_final')->default(false);
            $table->boolean('counts_toward_completion')->default(true);
            $table->date('scheduled_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 24)->default('DRAFT')->index();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('activity_assessment_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('activity_assessments')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('activity_memberships')->nullOnDelete();
            $table->decimal('score', 8, 2)->nullable();
            $table->string('level', 24)->nullable(); // BEGINNER|DEVELOPING|COMPETENT|ADVANCED
            $table->json('criteria_scores')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();
            $table->string('idempotency_key', 96)->nullable();
            $table->timestamps();
            $table->unique(['assessment_id', 'student_id'], 'activity_assessment_results_unique');
            $table->unique(['idempotency_key'], 'activity_assessment_results_idempotency_unique');
        });

        Schema::create('activity_skill_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('activity_skills')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('activity_programs')->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('activity_memberships')->nullOnDelete();
            $table->string('source', 24)->default('ASSESSMENT'); // ASSESSMENT|ACHIEVEMENT|PROJECT|ATTENDANCE|MANUAL|LEADERSHIP
            $table->string('level', 24)->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->text('evidence')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['student_id', 'skill_id'], 'activity_skill_evidence_lookup');
        });

        Schema::create('activity_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->unique()->constrained('activity_memberships')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('activity_periods')->nullOnDelete();
            $table->string('status', 24)->default('NOT_STARTED')->index();
            $table->integer('sessions_total')->default(0);
            $table->integer('sessions_attended')->default(0);
            $table->decimal('attendance_percentage', 5, 2)->default(0);
            $table->integer('assessments_total')->default(0);
            $table->integer('assessments_completed')->default(0);
            $table->decimal('average_score', 8, 2)->nullable();
            $table->json('skills')->nullable();
            $table->json('completion_snapshot')->nullable();
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('activity_memberships')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('activity_periods')->nullOnDelete();
            $table->string('status', 24)->default('PENDING')->index(); // PENDING|ELIGIBLE|COMPLETED|WAIVED
            $table->json('rule_snapshot')->nullable();
            $table->decimal('final_score', 8, 2)->nullable();
            $table->decimal('attendance_percentage', 5, 2)->nullable();
            $table->boolean('certificate_eligible')->default(false)->index();
            $table->text('notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('idempotency_key', 96)->nullable();
            $table->timestamps();
            $table->unique(['membership_id', 'period_id'], 'activity_completions_unique_period');
            $table->unique(['idempotency_key'], 'activity_completions_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_completions');
        Schema::dropIfExists('activity_progress');
        Schema::dropIfExists('activity_skill_evidence');
        Schema::dropIfExists('activity_assessment_results');
        Schema::dropIfExists('activity_assessments');
        Schema::dropIfExists('activity_rubric_items');
        Schema::dropIfExists('activity_rubrics');
    }
};
