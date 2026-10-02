<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_policies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->string('employment_type', 40)->nullable();
            $table->string('worker_type', 40)->nullable();
            $table->string('job_grade_code', 40)->nullable();
            $table->string('location_id', 60)->nullable();
            $table->integer('min_tenure_months')->default(0);
            $table->string('gender', 20)->nullable();
            $table->decimal('entitlement_days', 6,2)->default(0);
            $table->string('accrual_method', 191)->default('MONTHLY');
            $table->decimal('accrual_rate', 6,2)->default(0);
            $table->decimal('max_balance', 8,2)->nullable();
            $table->boolean('carry_forward')->default(false);
            $table->decimal('carry_forward_limit', 6,2)->nullable();
            $table->integer('min_notice_days')->default(1);
            $table->boolean('requires_document')->default(false);
            $table->integer('document_after_days')->default(2);
            $table->boolean('paid')->default(true);
            $table->unsignedBigInteger('eligibility_rule_id')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hr_leave_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('entitled_days', 8,2)->default(0);
            $table->decimal('accrual_rate', 6,2)->default(0);
            $table->string('source', 191)->default('POLICY');
            $table->dateTime('computed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_id', 'period_start']);
        });

        Schema::create('hr_leave_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
            $table->string('period_code', 10);
            $table->decimal('accrued_days', 6,2)->default(0);
            $table->date('accrual_date');
            $table->string('method', 40)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'period_code']);
        });

        Schema::create('hr_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
            $table->string('period_code', 10);
            $table->decimal('entitlement_days', 8,2)->default(0);
            $table->decimal('accrued_days', 8,2)->default(0);
            $table->decimal('used_days', 8,2)->default(0);
            $table->decimal('pending_days', 8,2)->default(0);
            $table->decimal('carry_forward_days', 8,2)->default(0);
            $table->decimal('expired_days', 8,2)->default(0);
            $table->decimal('adjustment_days', 8,2)->default(0);
            $table->decimal('available_days', 8,2)->default(0);
            $table->dateTime('last_accrued_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_id', 'period_code']);
        });

        Schema::create('hr_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 6,2)->default(0);
            $table->boolean('half_day')->default(false);
            $table->text('reason')->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->string('workflow_instance_id', 64)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->json('conflict_flags')->nullable();
            $table->json('balance_breakdown')->nullable();
            $table->boolean('attendance_applied')->default(false);
            $table->json('payroll_impact')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delegate_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'start_date']);
            $table->index(['status', 'start_date']);
        });

        Schema::create('hr_leave_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('hr_leave_requests')->cascadeOnDelete();
            $table->date('date');
            $table->string('day_type', 191)->default('FULL');
            $table->decimal('hours', 5,2)->nullable();
            $table->boolean('is_paid')->default(true);
            $table->boolean('counted')->default(true);
            $table->timestamps();
            $table->unique(['request_id', 'date']);
        });

        Schema::create('hr_leave_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('hr_leave_requests')->cascadeOnDelete();
            $table->integer('level')->default(1);
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('decision', 191)->default('PENDING');
            $table->dateTime('decided_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
            $table->string('period_code', 10);
            $table->string('adjustment_type', 191);
            $table->decimal('days', 6,2)->default(0);
            $table->text('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->unsignedBigInteger('balance_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hr_holiday_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('calendar_type', 191)->default('NATIONAL');
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->integer('year');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained('hr_holiday_calendars')->cascadeOnDelete();
            $table->date('date');
            $table->string('name', 191);
            $table->string('holiday_type', 40)->nullable();
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_recurring')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['calendar_id', 'date']);
        });

        // Kolom tambahan pada tabel Phase 1 — tidak menggandakan tabel referensi.
        if (! Schema::hasColumn('hr_leave_types', 'description')) {
            Schema::table('hr_leave_types', fn (Blueprint $table) => $table->text('description')->nullable());
        }
        if (! Schema::hasColumn('hr_leave_types', 'is_paid')) {
            Schema::table('hr_leave_types', fn (Blueprint $table) => $table->boolean('is_paid')->default(true));
        }
        if (! Schema::hasColumn('hr_leave_types', 'requires_document')) {
            Schema::table('hr_leave_types', fn (Blueprint $table) => $table->boolean('requires_document')->default(false));
        }
        if (! Schema::hasColumn('hr_leave_types', 'max_days_per_year')) {
            Schema::table('hr_leave_types', fn (Blueprint $table) => $table->decimal('max_days_per_year', 6, 2)->nullable());
        }
        if (! Schema::hasColumn('hr_leave_types', 'accrual_method')) {
            Schema::table('hr_leave_types', fn (Blueprint $table) => $table->string('accrual_method', 40)->nullable());
        }
        if (! Schema::hasColumn('hr_leave_types', 'color')) {
            Schema::table('hr_leave_types', fn (Blueprint $table) => $table->string('color', 20)->nullable());
        }
        if (! Schema::hasColumn('hr_leave_types', 'is_active')) {
            Schema::table('hr_leave_types', fn (Blueprint $table) => $table->boolean('is_active')->default(true));
        }
        if (! Schema::hasColumn('hr_leave_types', 'sort_order')) {
            Schema::table('hr_leave_types', fn (Blueprint $table) => $table->integer('sort_order')->default(0));
        }

    }

    public function down(): void
    {
        Schema::table('hr_leave_types', fn (Blueprint $table) => $table->dropColumn(['description', 'is_paid', 'requires_document', 'max_days_per_year', 'accrual_method', 'color', 'is_active', 'sort_order']));
        Schema::dropIfExists('hr_holidays');
        Schema::dropIfExists('hr_holiday_calendars');
        Schema::dropIfExists('hr_leave_adjustments');
        Schema::dropIfExists('hr_leave_approvals');
        Schema::dropIfExists('hr_leave_request_days');
        Schema::dropIfExists('hr_leave_requests');
        Schema::dropIfExists('hr_leave_balances');
        Schema::dropIfExists('hr_leave_accruals');
        Schema::dropIfExists('hr_leave_entitlements');
        Schema::dropIfExists('hr_leave_policies');
    }
};
