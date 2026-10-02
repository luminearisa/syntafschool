<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan transportasi, penugasan siswa, daftar tunggu, jadwal, dan
 * pengecualian jadwal (kalender sekolah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transportation_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 40)->unique();
            $table->string('request_type', 30);                // NEW, CHANGE_ROUTE, CHANGE_STOP, ...
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('guardian_id')->nullable()->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('requested_by_type', 20)->default('PARENT'); // PARENT, STAFF, SYSTEM
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('route_id')->nullable()->index();
            $table->unsignedBigInteger('morning_route_id')->nullable();
            $table->unsignedBigInteger('afternoon_route_id')->nullable();
            $table->unsignedBigInteger('pickup_stop_id')->nullable();
            $table->unsignedBigInteger('dropoff_stop_id')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('priority_level', 20)->default('NORMAL'); // LOW, NORMAL, HIGH, URGENT
            $table->text('reason')->nullable();
            $table->text('special_requirement')->nullable();
            $table->string('status', 24)->default('SUBMITTED'); // SUBMITTED, VALIDATED, ON_WAITLIST, APPROVED, REJECTED, ASSIGNED, CANCELLED
            $table->json('validation')->nullable();
            $table->json('capacity_check')->nullable();
            $table->string('workflow_reference')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('created_assignment_id')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status'], 'trequest_student_status_idx');
        });

        Schema::create('transportation_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('assignment_code', 40)->unique();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('request_id')->nullable()->index();
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('route_id')->nullable()->index();
            $table->unsignedBigInteger('morning_route_id')->nullable();
            $table->unsignedBigInteger('afternoon_route_id')->nullable();
            $table->unsignedBigInteger('pickup_stop_id')->nullable();
            $table->unsignedBigInteger('dropoff_stop_id')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('ACTIVE');   // PENDING, ACTIVE, SUSPENDED, ENDED, CANCELLED
            $table->string('transport_mode', 20)->default('RECURRING'); // RECURRING, TEMPORARY, SPECIAL
            $table->boolean('billable')->default(false);
            $table->decimal('fee_amount', 14, 2)->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspend_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status'], 'tassign_student_status_idx');
            $table->index(['route_id', 'status'], 'tassign_route_status_idx');
        });

        Schema::create('transportation_waitlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->index();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('route_id')->nullable()->index();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedInteger('priority_score')->default(0);
            $table->string('priority_class', 20)->default('NORMAL');
            $table->string('grade_level')->nullable();
            $table->boolean('special_need')->default(false);
            $table->boolean('boarding_status')->default(false);
            $table->boolean('manual_priority')->default(false);
            $table->timestamp('requested_at');
            $table->string('status', 20)->default('WAITING');   // WAITING, OFFERED, ACCEPTED, DECLINED, ASSIGNED, EXPIRED, CANCELLED
            $table->unsignedBigInteger('seat_offer_assignment_id')->nullable();
            $table->timestamp('offer_expires_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->unsignedBigInteger('promoted_by')->nullable();
            $table->json('history')->nullable();
            $table->timestamps();

            $table->index(['route_id', 'status', 'priority_score'], 'waitlist_route_status_idx');
        });

        Schema::create('transportation_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->unsignedBigInteger('route_id')->index();
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->string('schedule_type', 24)->default('WEEKLY'); // DAILY, WEEKLY, SCHOOL_DAYS, SPECIFIC_DATES, EXAM, SPECIAL
            $table->string('direction', 16)->default('PICKUP');     // PICKUP, DROPOFF
            $table->json('days_of_week')->nullable();               // [1..7]
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->json('specific_dates')->nullable();
            $table->time('departure_time');
            $table->time('expected_arrival_time')->nullable();
            $table->unsignedInteger('lead_minutes')->default(30);
            $table->unsignedBigInteger('default_vehicle_id')->nullable();
            $table->unsignedBigInteger('default_driver_profile_id')->nullable();
            $table->boolean('holiday_exception')->default(true);
            $table->boolean('active')->default(true);
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('schedule_id')->nullable()->index();
            $table->unsignedBigInteger('route_id')->nullable()->index();
            $table->date('exception_date');
            $table->string('exception_type', 30);              // HOLIDAY, EXAM, EARLY_DISMISSAL, LATE_DISMISSAL, EVENT, CANCELLED
            $table->time('override_departure_time')->nullable();
            $table->time('override_arrival_time')->nullable();
            $table->string('source', 20)->default('MANUAL');   // MANUAL, ACADEMIC_CALENDAR, EVENT, SYSTEM
            $table->string('source_reference')->nullable();
            $table->boolean('cancel_trips')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['exception_date', 'route_id'], 'sched_exception_date_route_idx');
        });
    }

    public function down(): void
    {
        foreach ([
            'schedule_exceptions', 'transportation_schedules', 'transportation_waitlists',
            'transportation_assignments', 'transportation_requests',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
