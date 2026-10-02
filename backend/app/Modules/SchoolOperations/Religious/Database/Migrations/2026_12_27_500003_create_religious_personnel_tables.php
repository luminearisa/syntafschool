<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil & penugasan imam, muadzin, khatib, ketersediaan personel, dan profil guru agama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('religious_personnel_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('person_type', 20)->default('EMPLOYEE');
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('code', 40)->unique();
            $table->string('full_name', 160);
            $table->string('gender', 12)->nullable();
            $table->string('external_name', 160)->nullable();
            $table->string('external_phone', 30)->nullable();
            $table->string('external_organization', 160)->nullable();
            $table->string('specialization', 120)->nullable();
            $table->string('qualification', 160)->nullable();
            $table->text('bio')->nullable();
            $table->json('languages')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->dateTime('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('imam_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('personnel_id')->index();
            $table->string('imam_code', 40);
            $table->string('qualification', 160)->nullable();
            $table->string('specialization', 120)->nullable();
            $table->string('scope_type', 20)->default('CAMPUS');
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->json('availability')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->decimal('rating', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('imam_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('imam_id')->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->string('prayer_type', 30)->nullable();
            $table->string('assignment_type', 20)->default('RECURRING');
            $table->unsignedBigInteger('roster_id')->nullable()->index();
            $table->unsignedBigInteger('prayer_schedule_id')->nullable()->index();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('day_of_week', 12)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedBigInteger('assigned_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('muadzin_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('personnel_id')->index();
            $table->string('muadzin_code', 40);
            $table->string('qualification', 160)->nullable();
            $table->string('scope_type', 20)->default('CAMPUS');
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->json('availability')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('muadzin_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('muadzin_id')->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->string('prayer_type', 30)->nullable();
            $table->string('assignment_type', 20)->default('RECURRING');
            $table->unsignedBigInteger('roster_id')->nullable()->index();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('day_of_week', 12)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedBigInteger('assigned_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('khatib_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('personnel_id')->index();
            $table->string('khatib_code', 40);
            $table->string('qualification', 160)->nullable();
            $table->string('specialization', 120)->nullable();
            $table->json('languages')->nullable();
            $table->text('bio')->nullable();
            $table->string('scope_type', 20)->default('CAMPUS');
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('khatib_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('khatib_id')->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->unsignedBigInteger('friday_prayer_id')->nullable()->index();
            $table->date('assignment_date');
            $table->string('topic', 200)->nullable();
            $table->string('language', 20)->nullable();
            $table->string('status', 20)->default('ASSIGNED');
            $table->unsignedBigInteger('assigned_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_personnel_availability', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('personnel_id')->index();
            $table->date('availability_date')->nullable();
            $table->string('day_of_week', 12)->nullable();
            $table->time('time_from')->nullable();
            $table->time('time_to')->nullable();
            $table->string('availability_type', 20)->default('AVAILABLE');
            $table->text('reason')->nullable();
            $table->boolean('recurring')->default(false);
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('personnel_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->unsignedBigInteger('teacher_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('religious_specialization', 160)->nullable();
            $table->json('subjects')->nullable();
            $table->string('qualification', 160)->nullable();
            $table->string('certification_body', 160)->nullable();
            $table->json('teaching_levels')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('religious_teacher_profiles');
        Schema::dropIfExists('religious_personnel_availability');
        Schema::dropIfExists('khatib_assignments');
        Schema::dropIfExists('khatib_profiles');
        Schema::dropIfExists('muadzin_assignments');
        Schema::dropIfExists('muadzin_profiles');
        Schema::dropIfExists('imam_assignments');
        Schema::dropIfExists('imam_profiles');
        Schema::dropIfExists('religious_personnel_profiles');
    }
};
