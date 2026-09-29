<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 Modul Supervisi — tindak lanjut, pengembangan, dan penilaian kinerja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sup_action_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->nullable()->constrained('sup_visits')->nullOnDelete();
            $table->unsignedBigInteger('supervisee_employee_id');
            $table->string('title', 200);
            $table->text('note')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supervisee_employee_id', 'status'], 'sup_plan_supervisee_idx');
        });

        Schema::create('sup_action_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_plan_id')->constrained('sup_action_plans')->cascadeOnDelete();
            $table->unsignedBigInteger('indicator_id')->nullable();
            $table->string('statement', 500);
            $table->text('support_needed')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('owner_employee_id')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('evidence_path', 255)->nullable();
            $table->text('result_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['action_plan_id', 'status'], 'sup_item_plan_idx');
            $table->index(['due_date', 'status'], 'sup_item_due_idx');
        });

        Schema::create('sup_action_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_item_id')->constrained('sup_action_items')->cascadeOnDelete();
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->text('note')->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->timestamps();

            $table->index(['action_item_id', 'reported_at'], 'sup_progress_item_idx');
        });

        Schema::create('sup_developments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supervisee_employee_id');
            $table->string('kind', 30)->default('DIKLAT');
            $table->string('title', 200);
            $table->string('provider', 150)->nullable();
            $table->decimal('hours', 6, 2)->nullable();
            $table->date('started_at')->nullable();
            $table->date('finished_at')->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->string('certificate_no', 80)->nullable();
            $table->string('certificate_path', 255)->nullable();
            $table->string('status', 20)->default('PLANNED');
            $table->unsignedBigInteger('linked_visit_id')->nullable();
            $table->unsignedBigInteger('linked_action_item_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['supervisee_employee_id', 'kind'], 'sup_development_idx');
        });

        Schema::create('sup_coaching_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supervisor_employee_id');
            $table->unsignedBigInteger('supervisee_employee_id');
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->timestamp('session_at')->nullable();
            $table->string('focus', 300)->nullable();
            $table->json('commitments')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['supervisee_employee_id', 'session_at'], 'sup_coaching_supervisee_idx');
        });

        Schema::create('sup_appraisals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('supervisee_employee_id');
            $table->string('appraisal_type', 20)->default('PKG');
            $table->string('period_type', 20)->default('SEMESTER');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('semester_id')->nullable();
            $table->unsignedBigInteger('instrument_version_id')->nullable();
            $table->unsignedBigInteger('assessor_employee_id')->nullable();
            $table->unsignedBigInteger('reviewer_employee_id')->nullable();
            $table->decimal('raw_score', 10, 2)->nullable();
            $table->decimal('max_score', 10, 2)->nullable();
            $table->decimal('final_score', 10, 2)->nullable();
            $table->string('grade_band', 30)->nullable();
            $table->string('grade_label', 60)->nullable();
            $table->decimal('credit_percent', 6, 2)->nullable();
            $table->unsignedInteger('attendance_absence_days')->nullable();
            $table->decimal('attendance_correction_percent', 6, 2)->nullable();
            $table->string('behavior_rating', 30)->nullable();
            $table->string('result_rating', 30)->nullable();
            $table->string('final_predicate', 40)->nullable();
            $table->decimal('supervision_weight', 6, 2)->nullable();
            $table->decimal('supervision_contribution', 8, 2)->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->json('snapshot')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supervisee_employee_id', 'period_start'], 'sup_appraisal_employee_idx');
            $table->index(['appraisal_type', 'status'], 'sup_appraisal_type_idx');
        });

        Schema::create('sup_appraisal_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appraisal_id')->constrained('sup_appraisals')->cascadeOnDelete();
            $table->unsignedBigInteger('indicator_id');
            $table->decimal('score', 8, 2)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['appraisal_id', 'indicator_id'], 'sup_appraisal_score_unique');
        });

        Schema::create('sup_appraisal_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appraisal_id')->constrained('sup_appraisals')->cascadeOnDelete();
            $table->string('source', 20);                            // SELF | SUPERVISOR | PEER | STUDENT | ...
            $table->unsignedBigInteger('rater_employee_id')->nullable();
            $table->unsignedBigInteger('rater_user_id')->nullable();
            $table->decimal('weight', 8, 2)->default(0);
            $table->decimal('score', 10, 2)->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['appraisal_id', 'source', 'rater_employee_id'], 'sup_appraisal_source_unique');
        });

        Schema::create('sup_pip_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('appraisal_id')->nullable();
            $table->unsignedBigInteger('supervisee_employee_id');
            $table->text('reason')->nullable();
            $table->text('expected_standard')->nullable();
            $table->text('consequences_note')->nullable();
            $table->text('support_provided')->nullable();
            $table->date('start_date')->nullable();
            $table->date('final_review_date')->nullable();
            $table->string('review_cadence', 20)->default('BIWEEKLY');
            $table->string('status', 20)->default('DRAFT');
            $table->string('outcome', 20)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supervisee_employee_id', 'status'], 'sup_pip_employee_idx');
        });

        Schema::create('sup_pip_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pip_plan_id')->constrained('sup_pip_plans')->cascadeOnDelete();
            $table->string('statement', 500);
            $table->string('measure', 200)->nullable();
            $table->decimal('target_value', 12, 2)->nullable();
            $table->string('target_unit', 40)->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->text('result_note')->nullable();
            $table->timestamps();

            $table->index(['pip_plan_id', 'status'], 'sup_pip_item_idx');
        });

        Schema::create('sup_pip_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pip_plan_id')->constrained('sup_pip_plans')->cascadeOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewer_employee_id')->nullable();
            $table->text('progress_note')->nullable();
            $table->string('next_step', 300)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->timestamps();

            $table->index(['pip_plan_id', 'reviewed_at'], 'sup_pip_review_idx');
        });

        Schema::create('sup_calibration_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->unsignedBigInteger('instrument_version_id')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('sup_calibration_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calibration_session_id')->constrained('sup_calibration_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('supervisor_employee_id');
            $table->unsignedBigInteger('indicator_id')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['calibration_session_id', 'supervisor_employee_id'], 'sup_calibration_score_idx');
        });

        Schema::create('sup_program_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained('sup_programs')->cascadeOnDelete();
            $table->string('model', 20)->default('CIPP');
            $table->json('context')->nullable();
            $table->json('input')->nullable();
            $table->json('process')->nullable();
            $table->json('product')->nullable();
            $table->decimal('context_score', 8, 2)->nullable();
            $table->decimal('input_score', 8, 2)->nullable();
            $table->decimal('process_score', 8, 2)->nullable();
            $table->decimal('product_score', 8, 2)->nullable();
            $table->text('finding')->nullable();
            $table->text('recommendation')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('sup_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('report_key', 60);                        // program_supervisi, hasil_guru, evaluasi, ...
            $table->json('parameters')->nullable();
            $table->string('path', 255)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('status', 20)->default('QUEUED');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['report_key', 'status'], 'sup_report_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sup_reports');
        Schema::dropIfExists('sup_program_evaluations');
        Schema::dropIfExists('sup_calibration_scores');
        Schema::dropIfExists('sup_calibration_sessions');
        Schema::dropIfExists('sup_pip_reviews');
        Schema::dropIfExists('sup_pip_items');
        Schema::dropIfExists('sup_pip_plans');
        Schema::dropIfExists('sup_appraisal_sources');
        Schema::dropIfExists('sup_appraisal_scores');
        Schema::dropIfExists('sup_appraisals');
        Schema::dropIfExists('sup_coaching_sessions');
        Schema::dropIfExists('sup_developments');
        Schema::dropIfExists('sup_action_progress');
        Schema::dropIfExists('sup_action_items');
        Schema::dropIfExists('sup_action_plans');
    }
};
