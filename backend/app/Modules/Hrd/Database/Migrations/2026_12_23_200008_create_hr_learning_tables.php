<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_training_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('title', 191);
            $table->string('training_type', 191)->default('COURSE');
            $table->string('provider', 191)->default('INTERNAL');
            $table->string('provider_name', 191)->nullable();
            $table->text('description')->nullable();
            $table->decimal('duration_hours', 8,2)->default(0);
            $table->decimal('cost', 15,2)->default(0);
            $table->string('currency', 191)->default('IDR');
            $table->string('delivery_mode', 191)->default('ONSITE');
            $table->string('lms_course_ref', 191)->nullable();
            $table->json('skills')->nullable();
            $table->json('competencies')->nullable();
            $table->string('certification_code', 60)->nullable();
            $table->integer('validity_months')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hr_training_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('training_id')->constrained('hr_training_catalog')->cascadeOnDelete();
            $table->string('scope', 191);
            $table->unsignedBigInteger('position_id')->nullable();
            $table->unsignedBigInteger('job_id')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->string('employment_type', 40)->nullable();
            $table->string('grade_code', 40)->nullable();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->integer('due_days')->nullable();
            $table->integer('recurrence_months')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['scope', 'is_active']);
        });

        Schema::create('hr_training_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('training_id')->constrained('hr_training_catalog')->cascadeOnDelete();
            $table->foreignId('requested_by_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->text('justification')->nullable();
            $table->decimal('estimated_cost', 15,2)->nullable();
            $table->string('currency', 191)->default('IDR');
            $table->string('budget_code', 60)->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->string('workflow_instance_id', 64)->nullable();
            $table->text('manager_decision_notes')->nullable();
            $table->text('hr_decision_notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('funding_source', 60)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'employee_id']);
        });

        Schema::create('hr_training_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('training_id')->constrained('hr_training_catalog')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('request_id')->nullable();
            $table->unsignedBigInteger('requirement_id')->nullable();
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->string('status', 191)->default('ASSIGNED');
            $table->string('lms_enrollment_ref', 191)->nullable();
            $table->unsignedBigInteger('completion_id')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->decimal('effort_hours', 8,2)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['training_id', 'employee_id']);
        });

        Schema::create('hr_training_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('training_id')->nullable();
            $table->unsignedBigInteger('request_id')->nullable();
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('cost_type', 191);
            $table->decimal('amount', 15,2)->default(0);
            $table->string('currency', 191)->default('IDR');
            $table->date('incurred_at')->nullable();
            $table->string('vendor', 191)->nullable();
            $table->string('receipt_path', 255)->nullable();
            $table->string('status', 191)->default('ESTIMATED');
            $table->string('finance_reference', 191)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->string('cost_center', 60)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['cost_type', 'status']);
        });

        Schema::create('hr_training_completions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('assignment_id')->constrained('hr_training_assignments')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('training_id')->nullable();
            $table->dateTime('completed_at');
            $table->decimal('score', 8,2)->nullable();
            $table->string('result', 191)->default('PASS');
            $table->text('assessment_notes')->nullable();
            $table->string('certificate_path', 255)->nullable();
            $table->string('certificate_number', 60)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->json('skill_updates')->nullable();
            $table->boolean('updated_skills')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['assignment_id']);
        });

        Schema::create('hr_training_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('completion_id')->constrained('hr_training_completions')->cascadeOnDelete();
            $table->string('assessment_type', 191);
            $table->decimal('score', 8,2)->default(0);
            $table->decimal('max_score', 8,2)->default(100);
            $table->string('result', 20)->nullable();
            $table->foreignId('assessor_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->dateTime('assessed_at')->useCurrent();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_learning_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->decimal('training_hours_total', 10,2)->default(0);
            $table->decimal('training_cost_total', 15,2)->default(0);
            $table->dateTime('last_training_at')->nullable();
            $table->integer('mandatory_completed')->default(0);
            $table->integer('mandatory_pending')->default(0);
            $table->integer('certifications_active')->default(0);
            $table->integer('certifications_expiring')->default(0);
            $table->string('preferred_learning_style', 60)->nullable();
            $table->text('career_aspiration')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['employee_id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('hr_learning_profiles');
        Schema::dropIfExists('hr_training_assessments');
        Schema::dropIfExists('hr_training_completions');
        Schema::dropIfExists('hr_training_costs');
        Schema::dropIfExists('hr_training_assignments');
        Schema::dropIfExists('hr_training_requests');
        Schema::dropIfExists('hr_training_requirements');
        Schema::dropIfExists('hr_training_catalog');
    }
};
