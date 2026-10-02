<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->time('start_time');
            $table->time('end_time');
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->integer('grace_minutes')->default(0);
            $table->boolean('cross_midnight')->default(false);
            $table->string('shift_type', 191)->default('MORNING');
            $table->decimal('total_hours', 5,2)->default(0);
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('schedule_type', 191)->default('FIXED');
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->decimal('weekly_hours', 5,2)->nullable();
            $table->json('working_days')->nullable();
            $table->time('flexible_start')->nullable();
            $table->time('flexible_end')->nullable();
            $table->time('core_start')->nullable();
            $table->time('core_end')->nullable();
            $table->boolean('is_default')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_work_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('hr_work_schedules')->cascadeOnDelete();
            $table->integer('weekday');
            $table->boolean('is_working')->default(true);
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->integer('break_minutes')->default(0);
            $table->decimal('hours', 5,2)->default(0);
            $table->timestamps();
            $table->unique(['schedule_id', 'weekday']);
        });

        Schema::create('hr_shift_rotations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('pattern', 191)->default('WEEKLY');
            $table->integer('cycle_weeks')->default(1);
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_shift_rotation_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rotation_id')->constrained('hr_shift_rotations')->cascadeOnDelete();
            $table->integer('week_index')->default(1);
            $table->integer('weekday');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('label', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('hr_employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('hr_work_schedules')->cascadeOnDelete();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->unsignedBigInteger('rotation_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('weekday_overrides')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('source', 191)->default('MANUAL');
            $table->string('status', 191)->default('ACTIVE');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('hr_attendance_locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('location_type', 191)->default('SCHOOL');
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->text('address')->nullable();
            $table->decimal('latitude', 10,7)->nullable();
            $table->decimal('longitude', 10,7)->nullable();
            $table->integer('radius_meters')->default(150);
            $table->boolean('geofence_enabled')->default(false);
            $table->string('timezone', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('provider', 191);
            $table->string('serial_number', 191)->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->string('direction', 191)->default('BOTH');
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_sync_at')->nullable();
            $table->json('config')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->dateTime('clock_in')->nullable();
            $table->dateTime('clock_out')->nullable();
            $table->string('clock_in_source', 40)->nullable();
            $table->string('clock_out_source', 40)->nullable();
            $table->integer('worked_minutes')->default(0);
            $table->integer('late_minutes')->default(0);
            $table->integer('early_leave_minutes')->default(0);
            $table->integer('overtime_minutes')->default(0);
            $table->string('status', 191)->default('ABSENT');
            $table->boolean('is_holiday')->default(false);
            $table->boolean('is_remote')->default(false);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('correction_id')->nullable();
            $table->unsignedBigInteger('import_id')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index(['date', 'status']);
        });

        Schema::create('hr_attendance_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendance_record_id')->nullable();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('event_type', 191);
            $table->dateTime('occurred_at');
            $table->string('provider', 191)->default('manual');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->decimal('latitude', 10,7)->nullable();
            $table->decimal('longitude', 10,7)->nullable();
            $table->decimal('accuracy', 10,2)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 191)->nullable();
            $table->json('raw')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->string('source_reference', 191)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'occurred_at']);
        });

        Schema::create('hr_attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->date('date');
            $table->string('correction_type', 191);
            $table->dateTime('requested_clock_in')->nullable();
            $table->dateTime('requested_clock_out')->nullable();
            $table->text('reason');
            $table->string('attachment_path', 255)->nullable();
            $table->string('status', 191)->default('SUBMITTED');
            $table->string('workflow_instance_id', 64)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'date']);
        });

        Schema::create('hr_timesheets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('period_type', 191)->default('WEEKLY');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_hours', 8,2)->default(0);
            $table->decimal('billable_hours', 8,2)->default(0);
            $table->string('status', 191)->default('DRAFT');
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('project_ref', 191)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'period_start']);
        });

        Schema::create('hr_timesheet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_id')->constrained('hr_timesheets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->decimal('hours', 5,2)->default(0);
            $table->string('project_code', 60)->nullable();
            $table->string('task_code', 60)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_billable')->default(false);
            $table->string('cost_center', 60)->nullable();
            $table->boolean('approved')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('planned_start')->nullable();
            $table->dateTime('planned_end')->nullable();
            $table->integer('planned_minutes')->default(0);
            $table->string('overtime_type', 191)->default('WORKDAY');
            $table->text('reason')->nullable();
            $table->string('status', 191)->default('DRAFT');
            $table->string('workflow_instance_id', 64)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->string('evidence_path', 255)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'date']);
            $table->index(['status', 'date']);
        });

        Schema::create('hr_overtime_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('overtime_request_id')->nullable();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();
            $table->integer('actual_minutes')->default(0);
            $table->integer('verified_minutes')->default(0);
            $table->decimal('multiplier', 5,2)->default(1);
            $table->decimal('rate_amount', 15,2)->nullable();
            $table->decimal('amount', 15,2)->nullable();
            $table->string('status', 191)->default('PENDING');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->unsignedBigInteger('attendance_record_id')->nullable();
            $table->unsignedBigInteger('payroll_item_id')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'date', 'overtime_request_id']);
        });

        Schema::create('hr_business_trips', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('trip_type', 191)->default('BUSINESS_TRIP');
            $table->string('title', 191);
            $table->string('destination', 191)->nullable();
            $table->text('purpose')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('days')->default(1);
            $table->string('status', 191)->default('DRAFT');
            $table->string('workflow_instance_id', 64)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->decimal('cost_estimate', 15,2)->nullable();
            $table->decimal('cost_actual', 15,2)->nullable();
            $table->string('fund_source', 191)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'start_date']);
        });

        Schema::create('hr_business_trip_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('hr_business_trips')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('role', 60)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['trip_id', 'employee_id']);
        });

        Schema::create('hr_workforce_imports', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('provider', 191);
            $table->string('kind', 191);
            $table->string('source_reference', 191)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->string('file_path', 255)->nullable();
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->string('status', 191)->default('RUNNING');
            $table->dateTime('started_at')->useCurrent();
            $table->dateTime('finished_at')->nullable();
            $table->json('errors')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('hr_workforce_imports');
        Schema::dropIfExists('hr_business_trip_participants');
        Schema::dropIfExists('hr_business_trips');
        Schema::dropIfExists('hr_overtime_records');
        Schema::dropIfExists('hr_overtime_requests');
        Schema::dropIfExists('hr_timesheet_entries');
        Schema::dropIfExists('hr_timesheets');
        Schema::dropIfExists('hr_attendance_corrections');
        Schema::dropIfExists('hr_attendance_events');
        Schema::dropIfExists('hr_attendance_records');
        Schema::dropIfExists('hr_attendance_devices');
        Schema::dropIfExists('hr_attendance_locations');
        Schema::dropIfExists('hr_employee_schedules');
        Schema::dropIfExists('hr_shift_rotation_patterns');
        Schema::dropIfExists('hr_shift_rotations');
        Schema::dropIfExists('hr_work_schedule_days');
        Schema::dropIfExists('hr_work_schedules');
        Schema::dropIfExists('hr_shifts');
    }
};
