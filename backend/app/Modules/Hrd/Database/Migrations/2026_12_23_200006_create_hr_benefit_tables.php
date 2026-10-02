<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_benefit_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('category', 191);
            $table->boolean('is_statutory')->default(false);
            $table->string('provider_name', 191)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_benefit_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->foreignId('benefit_type_id')->constrained('hr_benefit_types')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->string('coverage_tier', 60)->nullable();
            $table->decimal('employee_contribution', 15,2)->default(0);
            $table->decimal('employer_contribution', 15,2)->default(0);
            $table->string('contribution_period', 191)->default('MONTHLY');
            $table->string('salary_component_code', 60)->nullable();
            $table->integer('waiting_days')->default(0);
            $table->string('enrollment_window', 191)->default('ANNUAL');
            $table->boolean('requires_dependents')->default(false);
            $table->integer('max_dependents')->nullable();
            $table->unsignedBigInteger('eligibility_rule_id')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('plan_document_path', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hr_benefit_eligibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('hr_benefit_plans')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->string('employment_type', 40)->nullable();
            $table->string('worker_type', 40)->nullable();
            $table->string('grade_code', 40)->nullable();
            $table->integer('min_tenure_months')->nullable();
            $table->integer('min_age')->nullable();
            $table->integer('max_age')->nullable();
            $table->boolean('is_eligible')->default(true);
            $table->text('reason')->nullable();
            $table->dateTime('evaluated_at')->useCurrent();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['plan_id', 'employee_id']);
        });

        Schema::create('hr_benefit_enrollments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('hr_benefit_plans')->cascadeOnDelete();
            $table->string('status', 191)->default('DRAFT');
            $table->date('coverage_start');
            $table->date('coverage_end')->nullable();
            $table->decimal('employee_contribution', 15,2)->default(0);
            $table->decimal('employer_contribution', 15,2)->default(0);
            $table->integer('dependent_count')->default(0);
            $table->string('policy_number', 60)->nullable();
            $table->dateTime('elected_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('declined_reason', 191)->nullable();
            $table->string('workflow_instance_id', 64)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['employee_id', 'plan_id']);
        });

        Schema::create('hr_benefit_dependents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('hr_benefit_enrollments')->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('relationship', 191);
            $table->string('national_id', 60)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->boolean('is_covered')->default(true);
            $table->string('verification_status', 191)->default('PENDING');
            $table->string('document_path', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_benefit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('hr_benefit_enrollments')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('transaction_type', 191);
            $table->date('effective_date');
            $table->decimal('amount', 15,2)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 191)->nullable();
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'transaction_type']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('hr_benefit_transactions');
        Schema::dropIfExists('hr_benefit_dependents');
        Schema::dropIfExists('hr_benefit_enrollments');
        Schema::dropIfExists('hr_benefit_eligibilities');
        Schema::dropIfExists('hr_benefit_plans');
        Schema::dropIfExists('hr_benefit_types');
    }
};
