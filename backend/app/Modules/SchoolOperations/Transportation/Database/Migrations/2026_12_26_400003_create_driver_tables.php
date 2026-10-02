<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengemudi: profil (dari Employee HRD), SIM, kualifikasi, pelatihan,
 * ketersediaan, dan penugasan (termasuk pengemudi pengganti).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->nullable()->index();   // sumber: HRD
            $table->unsignedBigInteger('user_id')->nullable()->index();       // akun login aplikasi driver
            $table->string('driver_code', 40)->unique();
            $table->string('full_name')->nullable();                          // cache tampilan, sumber tetap HRD
            $table->string('phone', 30)->nullable();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->string('license_type', 20)->nullable();
            $table->string('license_number')->nullable();
            $table->date('license_expiry')->nullable();
            $table->string('employment_status', 30)->nullable();
            $table->string('driver_status', 24)->default('AVAILABLE');
            $table->string('assigned_vehicle_id')->nullable();
            $table->unsignedInteger('max_working_minutes_per_day')->nullable();
            $table->date('joined_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('driver_licenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_profile_id')->index();
            $table->string('license_type', 20);               // SIM A, SIM B1, ...
            $table->string('license_number');
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->string('issuing_authority')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('verified')->default(false);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('file_path')->nullable();
            $table->string('status', 20)->default('ACTIVE');  // ACTIVE, EXPIRED, SUSPENDED, REVOKED
            $table->timestamps();
        });

        Schema::create('driver_qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_profile_id')->index();
            $table->string('qualification_type', 40);          // DEFENSIVE_DRIVING, FIRST_AID, ...
            $table->string('level', 20)->nullable();
            $table->string('certificate_number')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('status', 20)->default('VALID');    // VALID, EXPIRED, PENDING, REVOKED
            $table->string('file_path')->nullable();
            $table->timestamps();

            $table->unique(['driver_profile_id', 'qualification_type'], 'driver_qual_unique');
        });

        Schema::create('driver_training_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_profile_id')->index();
            $table->string('training_name');
            $table->string('training_type', 40)->nullable();
            $table->string('provider')->nullable();
            $table->date('started_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->string('result', 20)->nullable();          // PASSED, FAILED, ATTENDED
            $table->unsignedInteger('score')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('certificate_number')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('driver_availability_windows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_profile_id')->index();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('status', 20)->default('AVAILABLE');  // AVAILABLE, LEAVE, OFF_DUTY, SUSPENDED
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['driver_profile_id', 'date'], 'daw_driver_date_idx');
        });

        Schema::create('driver_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_profile_id')->index();
            $table->unsignedBigInteger('trip_id')->nullable()->index();
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            $table->string('assignment_role', 20)->default('PRIMARY');  // PRIMARY, BACKUP, TEMPORARY, CO_DRIVER
            $table->string('status', 20)->default('ASSIGNED');          // ASSIGNED, ACTIVE, COMPLETED, REPLACED, CANCELLED
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('replaces_assignment_id')->nullable();
            $table->string('replacement_reason')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->json('validation')->nullable();
            $table->timestamps();

            $table->index(['driver_profile_id', 'starts_at', 'ends_at'], 'driver_asg_window_idx');
        });
    }

    public function down(): void
    {
        foreach ([
            'driver_assignments', 'driver_availability_windows', 'driver_training_records',
            'driver_qualifications', 'driver_licenses', 'driver_profiles',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
