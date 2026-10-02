<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_salary_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('kind', 191)->default('EARNING');
            $table->string('category', 60)->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_prorated')->default(false);
            $table->boolean('is_fixed')->default(true);
            $table->boolean('is_statutory')->default(false);
            $table->string('calculation_type', 191)->default('FIXED');
            $table->text('formula')->nullable();
            $table->string('percentage_of', 60)->nullable();
            $table->decimal('default_amount', 15,2)->nullable();
            $table->string('unit_label', 40)->nullable();
            $table->string('gl_account_code', 60)->nullable();
            $table->integer('order_index')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_salary_structures', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->string('job_grade_code', 40)->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('employment_type', 40)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('currency', 191)->default('IDR');
            $table->decimal('base_amount', 15,2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hr_salary_structure_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('structure_id')->constrained('hr_salary_structures')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('hr_salary_components')->cascadeOnDelete();
            $table->decimal('amount', 15,2)->nullable();
            $table->decimal('percentage', 6,2)->nullable();
            $table->text('formula')->nullable();
            $table->boolean('is_optional')->default(false);
            $table->integer('order_index')->default(0);
            $table->timestamps();
            $table->unique(['structure_id', 'component_id']);
        });

        Schema::create('hr_employee_salary_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('structure_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('base_amount', 15,2)->default(0);
            $table->string('currency', 191)->default('IDR');
            $table->string('status', 191)->default('ACTIVE');
            $table->string('reason', 191)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('previous_assignment_id')->nullable();
            $table->string('workflow_instance_id', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('hr_employee_salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('hr_salary_components')->cascadeOnDelete();
            $table->decimal('amount', 15,2)->nullable();
            $table->decimal('percentage', 6,2)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('source', 191)->default('MANUAL');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'is_active']);
        });

        Schema::create('hr_payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('period_type', 191)->default('MONTHLY');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('pay_date')->nullable();
            $table->date('cutoff_date')->nullable();
            $table->string('status', 191)->default('OPEN');
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->integer('working_days')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'start_date']);
        });

        Schema::create('hr_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('period_id')->constrained('hr_payroll_periods')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->string('run_type', 191)->default('REGULAR');
            $table->string('status', 191)->default('DRAFT');
            $table->integer('employee_count')->default(0);
            $table->decimal('gross_total', 18,2)->default(0);
            $table->decimal('earning_total', 18,2)->default(0);
            $table->decimal('deduction_total', 18,2)->default(0);
            $table->decimal('tax_total', 18,2)->default(0);
            $table->decimal('benefit_total', 18,2)->default(0);
            $table->decimal('net_total', 18,2)->default(0);
            $table->decimal('employer_cost_total', 18,2)->default(0);
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('finalized_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->string('journal_reference', 191)->nullable();
            $table->json('variance_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'period_id']);
        });

        Schema::create('hr_payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('employee_number', 60)->nullable();
            $table->string('employee_name', 191)->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('employment_type', 40)->nullable();
            $table->decimal('base_amount', 15,2)->default(0);
            $table->decimal('earning_total', 15,2)->default(0);
            $table->decimal('deduction_total', 15,2)->default(0);
            $table->decimal('tax_total', 15,2)->default(0);
            $table->decimal('benefit_total', 15,2)->default(0);
            $table->decimal('overtime_amount', 15,2)->default(0);
            $table->decimal('gross_amount', 15,2)->default(0);
            $table->decimal('net_amount', 15,2)->default(0);
            $table->decimal('prorated_days', 6,2)->default(0);
            $table->decimal('absent_days', 6,2)->default(0);
            $table->decimal('unpaid_leave_days', 6,2)->default(0);
            $table->integer('overtime_minutes')->default(0);
            $table->string('currency', 191)->default('IDR');
            $table->string('status', 191)->default('CALCULATED');
            $table->json('snapshot')->nullable();
            $table->dateTime('calculated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'employee_id']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('hr_payroll_item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('hr_payroll_items')->cascadeOnDelete();
            $table->unsignedBigInteger('component_id')->nullable();
            $table->string('component_code', 60);
            $table->string('component_name', 191)->nullable();
            $table->string('kind', 191);
            $table->decimal('amount', 15,2)->default(0);
            $table->decimal('quantity', 10,2)->nullable();
            $table->decimal('rate', 15,2)->nullable();
            $table->boolean('is_prorated')->default(false);
            $table->boolean('is_taxable')->default(true);
            $table->string('source', 40)->nullable();
            $table->json('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['item_id', 'kind']);
        });

        Schema::create('hr_payroll_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->string('period_code', 10);
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->boolean('is_immutable')->default(true);
            $table->json('payload');
            $table->string('checksum', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['run_id', 'employee_id']);
        });

        Schema::create('hr_payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('period_id')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->string('component_code', 60);
            $table->string('adjustment_type', 191);
            $table->decimal('amount', 15,2)->default(0);
            $table->text('reason');
            $table->string('status', 191)->default('DRAFT');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('applied_run_id')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('hr_payroll_taxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('period_code', 10);
            $table->string('tax_type', 191);
            $table->decimal('taxable_base', 15,2)->default(0);
            $table->decimal('rate', 8,4)->default(0);
            $table->decimal('amount', 15,2)->default(0);
            $table->string('rule_version', 40)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'period_code']);
        });

        Schema::create('hr_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('tax_type', 191);
            $table->string('version', 40);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('brackets')->nullable();
            $table->json('rates')->nullable();
            $table->decimal('employee_rate', 8,4)->nullable();
            $table->decimal('employer_rate', 8,4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_payslips', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('period_id')->nullable();
            $table->string('period_code', 10);
            $table->string('number', 60)->nullable();
            $table->decimal('gross_amount', 15,2)->default(0);
            $table->decimal('deduction_total', 15,2)->default(0);
            $table->decimal('tax_total', 15,2)->default(0);
            $table->decimal('net_amount', 15,2)->default(0);
            $table->string('currency', 191)->default('IDR');
            $table->dateTime('issued_at')->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->string('disk', 191)->default('local');
            $table->string('checksum', 64)->nullable();
            $table->string('status', 191)->default('GENERATED');
            $table->json('snapshot')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'employee_id']);
        });

        Schema::create('hr_payroll_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->string('stage', 191);
            $table->string('actor_role', 191);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 191)->default('PENDING');
            $table->dateTime('decided_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['run_id', 'stage']);
        });

        Schema::create('hr_payroll_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->string('journal_reference', 191)->unique();
            $table->string('posting_type', 191)->default('JOURNAL');
            $table->string('status', 191)->default('PENDING');
            $table->decimal('amount', 18,2)->default(0);
            $table->json('lines')->nullable();
            $table->string('finance_reference', 191)->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_postings');
        Schema::dropIfExists('hr_payroll_approvals');
        Schema::dropIfExists('hr_payslips');
        Schema::dropIfExists('hr_tax_rules');
        Schema::dropIfExists('hr_payroll_taxes');
        Schema::dropIfExists('hr_payroll_adjustments');
        Schema::dropIfExists('hr_payroll_snapshots');
        Schema::dropIfExists('hr_payroll_item_components');
        Schema::dropIfExists('hr_payroll_items');
        Schema::dropIfExists('hr_payroll_runs');
        Schema::dropIfExists('hr_payroll_periods');
        Schema::dropIfExists('hr_employee_salary_components');
        Schema::dropIfExists('hr_employee_salary_assignments');
        Schema::dropIfExists('hr_salary_structure_lines');
        Schema::dropIfExists('hr_salary_structures');
        Schema::dropIfExists('hr_salary_components');
    }
};
