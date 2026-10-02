<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presensi shalat (operasional, memakai Attendance Provider), penyesuaian, penutupan harian, jendela presensi, dan aturan asrama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prayer_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('prayer_schedule_id')->nullable()->index();
            $table->unsignedBigInteger('congregational_prayer_id')->nullable()->index();
            $table->string('prayer_type', 30);
            $table->date('attendance_date');
            $table->string('participant_type', 20)->default('STUDENT');
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('person_name', 160)->nullable();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('dormitory_id')->nullable()->index();
            $table->string('grade_level', 30)->nullable();
            $table->unsignedBigInteger('class_id')->nullable()->index();
            $table->string('status', 20)->default('PRESENT');
            $table->string('method', 20)->default('MANUAL');
            $table->unsignedBigInteger('recorded_by')->nullable()->index();
            $table->dateTime('recorded_at')->nullable();
            $table->unsignedInteger('minutes_late')->nullable();
            $table->string('latitude', 20)->nullable();
            $table->string('longitude', 20)->nullable();
            $table->string('device_code', 40)->nullable();
            $table->unsignedBigInteger('policy_id')->nullable()->index();
            $table->string('external_reference', 80)->nullable();
            $table->boolean('is_adjusted')->default(false);
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('prayer_attendance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendance_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('prayer_type', 30)->nullable();
            $table->date('attendance_date');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('adjusted_by')->index();
            $table->dateTime('adjusted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->unsignedBigInteger('evidence_document_id')->nullable()->index();
            $table->boolean('is_authorized')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_daily_closings', function (Blueprint $table) {
            $table->id();
            $table->date('closing_date');
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->string('scope_type', 20)->default('CAMPUS');
            $table->unsignedBigInteger('scope_id')->nullable()->index();
            $table->unsignedInteger('total_expected')->default(0);
            $table->unsignedInteger('present_count')->default(0);
            $table->unsignedInteger('late_count')->default(0);
            $table->unsignedInteger('absent_count')->default(0);
            $table->unsignedInteger('excused_count')->default(0);
            $table->unsignedInteger('authorized_absence_count')->default(0);
            $table->unsignedInteger('unknown_count')->default(0);
            $table->decimal('attendance_rate', 5, 2)->nullable();
            $table->unsignedBigInteger('closed_by')->nullable()->index();
            $table->dateTime('closed_at')->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->json('summary')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_attendance_windows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->nullable()->index();
            $table->string('prayer_type', 30);
            $table->string('window_type', 20)->default('PRAYER');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->time('late_after')->nullable();
            $table->json('applies_days')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_boarding_prayer_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dormitory_id')->nullable()->index();
            $table->string('grade_level', 30)->nullable();
            $table->unsignedBigInteger('program_id')->nullable()->index();
            $table->json('prayer_types')->nullable();
            $table->unsignedBigInteger('policy_id')->nullable()->index();
            $table->boolean('required')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('religious_boarding_prayer_rules');
        Schema::dropIfExists('religious_attendance_windows');
        Schema::dropIfExists('religious_daily_closings');
        Schema::dropIfExists('prayer_attendance_adjustments');
        Schema::dropIfExists('prayer_attendance_records');
    }
};
