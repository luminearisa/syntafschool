<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_rating_scales', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('scale_type', 191)->default('NUMERIC');
            $table->decimal('min_value', 6,2)->default(1);
            $table->decimal('max_value', 6,2)->default(5);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_rating_scale_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scale_id')->constrained('hr_rating_scales')->cascadeOnDelete();
            $table->string('label', 191);
            $table->decimal('min_value', 6,2)->default(0);
            $table->decimal('max_value', 6,2)->default(0);
            $table->integer('sequence')->default(0);
            $table->text('description')->nullable();
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_performance_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('cycle_type', 191)->default('ANNUAL');
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->date('goal_setting_start')->nullable();
            $table->date('goal_setting_end')->nullable();
            $table->date('mid_review_at')->nullable();
            $table->date('review_start')->nullable();
            $table->date('review_end')->nullable();
            $table->date('calibration_at')->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->unsignedBigInteger('rating_scale_id')->nullable();
            $table->boolean('include_competency')->default(true);
            $table->boolean('include_goals')->default(true);
            $table->decimal('weight_goals', 5,2)->default(60);
            $table->decimal('weight_competency', 5,2)->default(40);
            $table->string('workflow_instance_id', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'start_date']);
        });

        Schema::create('hr_performance_cycle_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('hr_performance_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('reviewer_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->string('status', 191)->default('INVITED');
            $table->dateTime('self_review_submitted_at')->nullable();
            $table->dateTime('manager_review_submitted_at')->nullable();
            $table->decimal('final_rating', 6,2)->nullable();
            $table->string('rating_label', 60)->nullable();
            $table->decimal('potential_rating', 6,2)->nullable();
            $table->string('nine_box_code', 20)->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['cycle_id', 'employee_id']);
        });

        Schema::create('hr_performance_goals', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('owner_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->unsignedBigInteger('parent_goal_id')->nullable();
            $table->string('scope', 191)->default('INDIVIDUAL');
            $table->string('goal_type', 191)->default('OBJECTIVE');
            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->string('metric', 191)->nullable();
            $table->string('unit', 40)->nullable();
            $table->decimal('target_value', 15,2)->nullable();
            $table->string('target_direction', 191)->default('INCREASE');
            $table->decimal('weight', 6,2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('progress', 6,2)->default(0);
            $table->string('status', 191)->default('DRAFT');
            $table->unsignedBigInteger('kpi_definition_id')->nullable();
            $table->string('source', 60)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'status']);
            $table->index(['cycle_id', 'scope']);
        });

        Schema::create('hr_goal_key_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained('hr_performance_goals')->cascadeOnDelete();
            $table->string('title', 191);
            $table->string('metric', 191)->nullable();
            $table->string('unit', 40)->nullable();
            $table->decimal('target_value', 15,2)->default(0);
            $table->decimal('current_value', 15,2)->default(0);
            $table->decimal('weight', 6,2)->default(0);
            $table->decimal('progress', 6,2)->default(0);
            $table->date('due_date')->nullable();
            $table->string('status', 191)->default('ACTIVE');
            $table->foreignId('owner_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_goal_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('goal_id')->nullable();
            $table->unsignedBigInteger('key_result_id')->nullable();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('update_type', 191)->default('PROGRESS');
            $table->decimal('progress', 6,2)->nullable();
            $table->decimal('previous_progress', 6,2)->nullable();
            $table->text('comment')->nullable();
            $table->text('blockers')->nullable();
            $table->string('evidence_path', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['goal_id', 'update_type']);
        });

        Schema::create('hr_kpi_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('category', 60)->nullable();
            $table->string('metric_type', 191)->default('NUMBER');
            $table->string('unit', 40)->nullable();
            $table->decimal('target_default', 15,2)->nullable();
            $table->string('frequency', 191)->default('MONTHLY');
            $table->string('source_module', 191)->default('HR');
            $table->text('formula')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_kpi_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_id')->constrained('hr_kpi_definitions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->string('period_code', 10);
            $table->decimal('value', 15,2)->default(0);
            $table->decimal('target', 15,2)->nullable();
            $table->decimal('achievement', 8,2)->nullable();
            $table->string('source', 60)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('recorded_at')->useCurrent();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['kpi_id', 'employee_id', 'period_code']);
        });

        Schema::create('hr_competency_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('category', 191)->default('CORE');
            $table->text('description')->nullable();
            $table->integer('max_level')->default(5);
            $table->decimal('weight', 6,2)->default(0);
            $table->string('skill_code', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_competency_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competency_id')->constrained('hr_competency_catalog')->cascadeOnDelete();
            $table->integer('level');
            $table->string('label', 191);
            $table->json('behavioral_indicators')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['competency_id', 'level']);
        });

        Schema::create('hr_position_competency_requirements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->unsignedBigInteger('job_id')->nullable();
            $table->foreignId('competency_id')->constrained('hr_competency_catalog')->cascadeOnDelete();
            $table->integer('required_level')->default(1);
            $table->decimal('weight', 6,2)->default(1);
            $table->boolean('is_mandatory')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['position_id', 'competency_id']);
        });

        Schema::create('hr_position_skill_requirements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->unsignedBigInteger('job_id')->nullable();
            $table->string('skill_code', 60);
            $table->string('required_level', 191)->default('BEGINNER');
            $table->decimal('weight', 6,2)->default(1);
            $table->boolean('is_mandatory')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['position_id', 'skill_code']);
        });

        Schema::create('hr_performance_competency_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('hr_competency_catalog')->cascadeOnDelete();
            $table->integer('current_level')->default(0);
            $table->integer('target_level')->default(0);
            $table->integer('gap')->default(0);
            $table->text('evidence')->nullable();
            $table->foreignId('reviewer_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->dateTime('assessed_at')->useCurrent();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'competency_id']);
        });

        Schema::create('hr_performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('cycle_id')->constrained('hr_performance_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('reviewer_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('review_type', 191)->default('MANAGER');
            $table->string('status', 191)->default('DRAFT');
            $table->decimal('goal_score', 6,2)->nullable();
            $table->decimal('competency_score', 6,2)->nullable();
            $table->decimal('overall_score', 6,2)->nullable();
            $table->string('rating_label', 60)->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('manager_comments')->nullable();
            $table->text('employee_comments')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('finalized_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['cycle_id', 'employee_id', 'reviewer_employee_id', 'review_type']);
        });

        Schema::create('hr_performance_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('reviewer_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->string('feedback_type', 191)->default('PEER');
            $table->string('visibility', 191)->default('MANAGER');
            $table->text('message');
            $table->decimal('rating', 6,2)->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('given_at')->useCurrent();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'feedback_type']);
        });

        Schema::create('hr_performance_calibrations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('cycle_id')->constrained('hr_performance_cycles')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->string('name', 191);
            $table->foreignId('facilitator_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->json('distribution')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_calibration_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calibration_id')->constrained('hr_performance_calibrations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->decimal('previous_rating', 6,2)->nullable();
            $table->decimal('calibrated_rating', 6,2)->nullable();
            $table->decimal('potential_rating', 6,2)->nullable();
            $table->string('nine_box_code', 20)->nullable();
            $table->text('justification')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['calibration_id', 'employee_id']);
        });

        Schema::create('hr_development_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->string('title', 191);
            $table->string('source', 191)->default('PERFORMANCE');
            $table->text('objective')->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->decimal('progress', 6,2)->default(0);
            $table->foreignId('owner_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('hr_development_plan_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('hr_development_plans')->cascadeOnDelete();
            $table->string('action_type', 191);
            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->string('skill_code', 60)->nullable();
            $table->unsignedBigInteger('competency_id')->nullable();
            $table->unsignedBigInteger('training_id')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 191)->default('PENDING');
            $table->decimal('progress', 6,2)->default(0);
            $table->string('evidence_path', 255)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_performance_improvement_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('manager_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->text('issue');
            $table->text('expectations')->nullable();
            $table->text('support')->nullable();
            $table->string('status', 191)->default('ACTIVE');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->json('review_dates')->nullable();
            $table->text('outcome')->nullable();
            $table->string('workflow_instance_id', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hr_pip_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pip_id')->constrained('hr_performance_improvement_plans')->cascadeOnDelete();
            $table->date('review_date');
            $table->foreignId('reviewer_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->decimal('progress', 6,2)->default(0);
            $table->string('status', 191)->default('ON_TRACK');
            $table->text('notes')->nullable();
            $table->text('next_steps')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('hr_pip_reviews');
        Schema::dropIfExists('hr_performance_improvement_plans');
        Schema::dropIfExists('hr_development_plan_actions');
        Schema::dropIfExists('hr_development_plans');
        Schema::dropIfExists('hr_calibration_entries');
        Schema::dropIfExists('hr_performance_calibrations');
        Schema::dropIfExists('hr_performance_feedback');
        Schema::dropIfExists('hr_performance_reviews');
        Schema::dropIfExists('hr_performance_competency_assessments');
        Schema::dropIfExists('hr_position_skill_requirements');
        Schema::dropIfExists('hr_position_competency_requirements');
        Schema::dropIfExists('hr_competency_levels');
        Schema::dropIfExists('hr_competency_catalog');
        Schema::dropIfExists('hr_kpi_results');
        Schema::dropIfExists('hr_kpi_definitions');
        Schema::dropIfExists('hr_goal_updates');
        Schema::dropIfExists('hr_goal_key_results');
        Schema::dropIfExists('hr_performance_goals');
        Schema::dropIfExists('hr_performance_cycle_participants');
        Schema::dropIfExists('hr_performance_cycles');
        Schema::dropIfExists('hr_rating_scale_levels');
        Schema::dropIfExists('hr_rating_scales');
    }
};
