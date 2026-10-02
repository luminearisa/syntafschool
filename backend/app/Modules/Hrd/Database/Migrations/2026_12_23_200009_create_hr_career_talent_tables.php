<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_career_paths', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->unsignedBigInteger('job_family_id')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_career_path_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('path_id')->constrained('hr_career_paths')->cascadeOnDelete();
            $table->integer('sequence')->default(1);
            $table->unsignedBigInteger('position_id')->nullable();
            $table->unsignedBigInteger('job_id')->nullable();
            $table->string('grade_code', 40)->nullable();
            $table->decimal('min_years_experience', 5,2)->nullable();
            $table->json('required_competencies')->nullable();
            $table->json('required_skills')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['path_id', 'sequence']);
        });

        Schema::create('hr_employee_career_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('target_position_id')->nullable();
            $table->unsignedBigInteger('target_job_id')->nullable();
            $table->unsignedBigInteger('path_id')->nullable();
            $table->unsignedBigInteger('current_position_id')->nullable();
            $table->decimal('readiness_score', 6,2)->nullable();
            $table->string('readiness_level', 40)->nullable();
            $table->json('gap_snapshot')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('status', 191)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('hr_internal_job_applications', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('opening_id')->constrained('hr_job_openings')->cascadeOnDelete();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->unsignedBigInteger('application_id')->nullable();
            $table->string('status', 191)->default('SUBMITTED');
            $table->dateTime('manager_notified_at')->nullable();
            $table->string('manager_decision', 40)->nullable();
            $table->string('hr_decision', 40)->nullable();
            $table->unsignedBigInteger('transfer_transaction_id')->nullable();
            $table->decimal('readiness_score', 6,2)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('hr_mentoring_programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->text('objective')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_mentor_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('hr_mentoring_programs')->cascadeOnDelete();
            $table->foreignId('mentor_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('mentee_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->json('focus_areas')->nullable();
            $table->string('status', 191)->default('ACTIVE');
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->decimal('progress', 6,2)->default(0);
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'mentor_employee_id', 'mentee_employee_id']);
        });

        Schema::create('hr_mentoring_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('hr_mentor_assignments')->cascadeOnDelete();
            $table->date('session_date');
            $table->integer('duration_minutes')->default(60);
            $table->string('topic', 191)->nullable();
            $table->text('notes')->nullable();
            $table->json('action_items')->nullable();
            $table->text('mentor_notes')->nullable();
            $table->text('mentee_notes')->nullable();
            $table->string('status', 191)->default('SCHEDULED');
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_talent_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->string('name', 191);
            $table->string('review_type', 191)->default('PERFORMANCE_POTENTIAL');
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('facilitator_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->json('participants')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hr_talent_review_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('hr_talent_reviews')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->decimal('performance_rating', 6,2)->nullable();
            $table->decimal('potential_rating', 6,2)->nullable();
            $table->string('nine_box_code', 20)->nullable();
            $table->string('readiness', 40)->nullable();
            $table->text('career_aspiration')->nullable();
            $table->string('risk_of_loss', 40)->nullable();
            $table->string('impact_of_loss', 40)->nullable();
            $table->json('development_actions')->nullable();
            $table->string('source_cycle_code', 40)->nullable();
            $table->unsignedBigInteger('source_review_id')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['review_id', 'employee_id']);
        });

        Schema::create('hr_talent_pools', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('pool_type', 191);
            $table->text('description')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('owner_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_talent_pool_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('hr_talent_pools')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('added_at')->useCurrent();
            $table->string('readiness', 40)->nullable();
            $table->integer('rank')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 191)->default('ACTIVE');
            $table->unsignedBigInteger('source_review_result_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['pool_id', 'employee_id']);
        });

        Schema::create('hr_potential_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->foreignId('assessed_by_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('assessment_method', 191)->default('MANAGER_RATING');
            $table->decimal('potential_score', 6,2)->default(0);
            $table->decimal('learning_agility', 6,2)->nullable();
            $table->json('aspiration')->nullable();
            $table->json('mobility')->nullable();
            $table->text('summary')->nullable();
            $table->dateTime('assessed_at')->useCurrent();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'cycle_id']);
        });

        Schema::create('hr_recognition_awards', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('category', 191)->default('AWARD');
            $table->text('description')->nullable();
            $table->integer('points')->default(0);
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_recognitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('award_id')->nullable();
            $table->string('recognition_type', 191)->default('THANK_YOU');
            $table->foreignId('given_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('given_by_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('source', 191)->default('PEER');
            $table->string('title', 191);
            $table->text('message')->nullable();
            $table->boolean('is_public')->default(true);
            $table->integer('points')->default(0);
            $table->dateTime('recognized_at')->useCurrent();
            $table->string('period_code', 10)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'recognized_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('hr_recognitions');
        Schema::dropIfExists('hr_recognition_awards');
        Schema::dropIfExists('hr_potential_assessments');
        Schema::dropIfExists('hr_talent_pool_members');
        Schema::dropIfExists('hr_talent_pools');
        Schema::dropIfExists('hr_talent_review_results');
        Schema::dropIfExists('hr_talent_reviews');
        Schema::dropIfExists('hr_mentoring_sessions');
        Schema::dropIfExists('hr_mentor_assignments');
        Schema::dropIfExists('hr_mentoring_programs');
        Schema::dropIfExists('hr_internal_job_applications');
        Schema::dropIfExists('hr_employee_career_plans');
        Schema::dropIfExists('hr_career_path_steps');
        Schema::dropIfExists('hr_career_paths');
    }
};
