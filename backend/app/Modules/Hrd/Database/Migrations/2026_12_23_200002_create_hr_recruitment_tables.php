<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_recruitment_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('hr_jobs')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('replacement_for_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->integer('headcount')->default(1);
            $table->string('employment_type', 40)->nullable();
            $table->string('worker_type', 40)->nullable();
            $table->string('priority', 191)->default('NORMAL');
            $table->string('reason', 191)->nullable();
            $table->decimal('budget', 15,2)->nullable();
            $table->date('target_hire_date')->nullable();
            $table->string('required_education', 60)->nullable();
            $table->json('required_skills')->nullable();
            $table->json('required_certifications')->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->string('workflow_instance_id', 64)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'organization_id']);
        });

        Schema::create('hr_job_openings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('requisition_id')->constrained('hr_recruitment_requisitions')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('hr_jobs')->nullOnDelete();
            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->string('visibility', 191)->default('EXTERNAL');
            $table->json('channels')->nullable();
            $table->integer('openings_count')->default(1);
            $table->integer('filled_count')->default(0);
            $table->dateTime('published_at')->nullable();
            $table->dateTime('closing_at')->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->boolean('show_salary')->default(false);
            $table->decimal('salary_min', 15,2)->nullable();
            $table->decimal('salary_max', 15,2)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'published_at']);
        });

        Schema::create('hr_candidate_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('category', 60)->nullable();
            $table->decimal('cost_per_hire', 15,2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_candidate_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('person_id')->nullable()->constrained('hr_people')->nullOnDelete();
            $table->string('full_name', 191);
            $table->string('email', 191)->index();
            $table->string('phone', 40)->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('education_level', 60)->nullable();
            $table->decimal('total_experience_years', 5,2)->nullable();
            $table->string('current_employer', 191)->nullable();
            $table->string('current_position', 191)->nullable();
            $table->decimal('expected_salary', 15,2)->nullable();
            $table->json('skills')->nullable();
            $table->json('certifications')->nullable();
            $table->json('languages')->nullable();
            $table->string('resume_path', 255)->nullable();
            $table->string('portfolio_url', 255)->nullable();
            $table->string('source_code', 60)->nullable();
            $table->string('source_detail', 191)->nullable();
            $table->string('linkedin_url', 255)->nullable();
            $table->string('status', 191)->default('ACTIVE');
            $table->boolean('is_talent_pool')->default(false);
            $table->json('parsed_resume')->nullable();
            $table->boolean('parsed_reviewed')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['email', 'status']);
        });

        Schema::create('hr_candidate_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('hr_candidate_profiles')->cascadeOnDelete();
            $table->string('category', 191);
            $table->string('title', 191)->nullable();
            $table->string('path', 191);
            $table->string('disk', 191)->default('local');
            $table->string('mime', 191)->nullable();
            $table->integer('size')->default(0);
            $table->date('expires_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_candidate_applications', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('candidate_id')->constrained('hr_candidate_profiles')->cascadeOnDelete();
            $table->foreignId('opening_id')->constrained('hr_job_openings')->cascadeOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('hr_recruitment_requisitions')->nullOnDelete();
            $table->string('source_code', 60)->nullable();
            $table->string('stage_code', 191)->default('APPLIED');
            $table->string('status', 191)->default('ACTIVE');
            $table->dateTime('applied_at')->useCurrent();
            $table->dateTime('stage_entered_at')->useCurrent();
            $table->decimal('rating', 5,2)->nullable();
            $table->string('rejection_reason', 191)->nullable();
            $table->string('withdrawal_reason', 191)->nullable();
            $table->foreignId('internal_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->decimal('screening_score', 8,2)->nullable();
            $table->decimal('assessment_score', 8,2)->nullable();
            $table->decimal('interview_score', 8,2)->nullable();
            $table->string('offer_status', 40)->nullable();
            $table->foreignId('hired_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->dateTime('converted_at')->nullable();
            $table->integer('time_to_hire_days')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['candidate_id', 'opening_id']);
            $table->index(['stage_code', 'status']);
        });

        Schema::create('hr_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('label', 191);
            $table->integer('sequence')->default(0);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('is_default')->default(false);
            $table->integer('sla_days')->nullable();
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_application_stage_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('hr_candidate_applications')->cascadeOnDelete();
            $table->string('from_stage', 40)->nullable();
            $table->string('to_stage', 191);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->dateTime('moved_at')->useCurrent();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'to_stage']);
        });

        Schema::create('hr_candidate_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('hr_candidate_applications')->cascadeOnDelete();
            $table->json('criteria')->nullable();
            $table->string('result', 191)->default('REVIEW');
            $table->decimal('score', 8,2)->nullable();
            $table->json('reasons')->nullable();
            $table->foreignId('screened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('screened_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_interview_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('label', 191);
            $table->integer('sequence')->default(0);
            $table->string('interview_type', 191);
            $table->integer('default_duration_minutes')->default(60);
            $table->unsignedBigInteger('scorecard_template_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_scorecard_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_scorecard_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('hr_scorecard_templates')->cascadeOnDelete();
            $table->string('competency_label', 191);
            $table->text('description')->nullable();
            $table->decimal('weight', 5,2)->default(1);
            $table->decimal('max_score', 5,2)->default(5);
            $table->integer('sequence')->default(0);
            $table->timestamps();
        });

        Schema::create('hr_interview_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('hr_candidate_applications')->cascadeOnDelete();
            $table->unsignedBigInteger('round_id')->nullable();
            $table->string('stage_code', 40)->nullable();
            $table->dateTime('scheduled_at');
            $table->integer('duration_minutes')->default(60);
            $table->string('interview_type', 191);
            $table->string('mode', 191)->default('ONSITE');
            $table->string('location', 191)->nullable();
            $table->string('meeting_link', 255)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('status', 191)->default('SCHEDULED');
            $table->dateTime('reminder_sent_at')->nullable();
            $table->string('calendar_event_ref', 191)->nullable();
            $table->string('cancel_reason', 191)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['scheduled_at', 'status']);
        });

        Schema::create('hr_interview_interviewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('hr_interview_schedules')->cascadeOnDelete();
            $table->foreignId('interviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 191)->default('PANEL');
            $table->boolean('is_lead')->default(false);
            $table->string('status', 191)->default('INVITED');
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['schedule_id', 'interviewer_user_id']);
        });

        Schema::create('hr_interview_scorecards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('hr_interview_schedules')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('hr_candidate_applications')->cascadeOnDelete();
            $table->foreignId('interviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->decimal('overall_score', 8,2)->nullable();
            $table->string('recommendation', 20)->nullable();
            $table->text('strengths')->nullable();
            $table->text('concerns')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->dateTime('submitted_at')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['schedule_id', 'interviewer_user_id']);
        });

        Schema::create('hr_interview_scorecard_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scorecard_id')->constrained('hr_interview_scorecards')->cascadeOnDelete();
            $table->string('item_label', 191);
            $table->decimal('weight', 5,2)->default(1);
            $table->decimal('score', 5,2)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_assessment_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('category', 191);
            $table->text('description')->nullable();
            $table->decimal('max_score', 8,2)->default(100);
            $table->decimal('passing_score', 8,2)->nullable();
            $table->decimal('weight', 5,2)->default(1);
            $table->string('provider', 191)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_candidate_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('hr_assessment_definitions')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('hr_candidate_applications')->cascadeOnDelete();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('score', 8,2)->nullable();
            $table->string('result', 191)->default('PENDING');
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('criteria_scores')->nullable();
            $table->string('evidence_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 191)->default('PENDING');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'result']);
        });

        Schema::create('hr_job_offers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('application_id')->constrained('hr_candidate_applications')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('hr_candidate_profiles')->cascadeOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('hr_recruitment_requisitions')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('hr_jobs')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->string('employment_type', 40)->nullable();
            $table->string('worker_type', 40)->nullable();
            $table->decimal('offered_salary', 15,2)->nullable();
            $table->json('salary_breakdown')->nullable();
            $table->date('start_date')->nullable();
            $table->string('contract_type_code', 40)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->string('decline_reason', 191)->nullable();
            $table->string('document_path', 255)->nullable();
            $table->string('workflow_instance_id', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('hr_offer_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('hr_job_offers')->cascadeOnDelete();
            $table->integer('level')->default(1);
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 191)->default('PENDING');
            $table->dateTime('decided_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offer_approvals');
        Schema::dropIfExists('hr_job_offers');
        Schema::dropIfExists('hr_candidate_assessments');
        Schema::dropIfExists('hr_assessment_definitions');
        Schema::dropIfExists('hr_interview_scorecard_scores');
        Schema::dropIfExists('hr_interview_scorecards');
        Schema::dropIfExists('hr_interview_interviewers');
        Schema::dropIfExists('hr_interview_schedules');
        Schema::dropIfExists('hr_scorecard_template_items');
        Schema::dropIfExists('hr_scorecard_templates');
        Schema::dropIfExists('hr_interview_rounds');
        Schema::dropIfExists('hr_candidate_screenings');
        Schema::dropIfExists('hr_application_stage_histories');
        Schema::dropIfExists('hr_pipeline_stages');
        Schema::dropIfExists('hr_candidate_applications');
        Schema::dropIfExists('hr_candidate_documents');
        Schema::dropIfExists('hr_candidate_profiles');
        Schema::dropIfExists('hr_candidate_sources');
        Schema::dropIfExists('hr_job_openings');
        Schema::dropIfExists('hr_recruitment_requisitions');
    }
};
