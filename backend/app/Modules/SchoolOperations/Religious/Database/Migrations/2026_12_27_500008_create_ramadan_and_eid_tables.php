<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Program Ramadan, hari Ramadan, aktivitas, tarawih, tahajjud, iftar, suhoor, i'tikaf, hari raya, dan qurban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ramadan_programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('hijri_year', 12);
            $table->date('gregorian_start')->nullable();
            $table->date('gregorian_end')->nullable();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->json('program_types')->nullable();
            $table->string('target_population', 20)->default('ALL');
            $table->unsignedInteger('expected_participants')->nullable();
            $table->decimal('budget', 18, 2)->nullable();
            $table->string('currency', 10)->default('IDR');
            $table->string('status', 20)->default('PLANNED');
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('ramadan_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_id')->index();
            $table->unsignedInteger('hijri_day')->default(0);
            $table->date('gregorian_date');
            $table->time('fasting_start')->nullable();
            $table->time('fasting_end')->nullable();
            $table->boolean('is_holiday')->default(false);
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('ramadan_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_id')->index();
            $table->unsignedBigInteger('ramadan_day_id')->nullable()->index();
            $table->string('activity_type', 30)->default('TARAWIH');
            $table->string('title', 200);
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->unsignedBigInteger('imam_id')->nullable()->index();
            $table->unsignedBigInteger('muadzin_id')->nullable()->index();
            $table->unsignedInteger('rakaat_count')->nullable();
            $table->json('rakaat_config')->nullable();
            $table->string('target_population', 20)->default('ALL');
            $table->unsignedInteger('expected_participants')->nullable();
            $table->unsignedInteger('actual_participants')->nullable();
            $table->string('catering_reference', 80)->nullable();
            $table->string('transportation_reference', 80)->nullable();
            $table->string('status', 20)->default('PLANNED');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('tarawih_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_id')->index();
            $table->unsignedBigInteger('ramadan_day_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->date('session_date');
            $table->unsignedBigInteger('imam_id')->nullable()->index();
            $table->unsignedBigInteger('muadzin_id')->nullable()->index();
            $table->unsignedInteger('rakaat_count')->nullable();
            $table->json('rakaat_config')->nullable();
            $table->unsignedInteger('witr_count')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedInteger('expected_participants')->nullable();
            $table->unsignedInteger('actual_participants')->nullable();
            $table->string('status', 20)->default('SCHEDULED');
            $table->dateTime('attendance_closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('tahajjud_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_id')->nullable()->index();
            $table->unsignedBigInteger('ramadan_day_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('dormitory_id')->nullable()->index();
            $table->date('session_date');
            $table->unsignedBigInteger('imam_id')->nullable()->index();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('program_name', 160)->nullable();
            $table->boolean('is_special')->default(false);
            $table->string('target_population', 20)->default('BOARDING');
            $table->unsignedInteger('expected_participants')->nullable();
            $table->unsignedInteger('actual_participants')->nullable();
            $table->string('status', 20)->default('SCHEDULED');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('iftar_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_id')->index();
            $table->unsignedBigInteger('ramadan_day_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->date('event_date');
            $table->time('start_time')->nullable();
            $table->unsignedInteger('expected_participants')->nullable();
            $table->unsignedInteger('actual_participants')->nullable();
            $table->string('meal_plan_reference', 80)->nullable();
            $table->boolean('catering_required')->default(true);
            $table->string('catering_request_status', 20)->nullable();
            $table->boolean('transportation_required')->default(false);
            $table->string('transportation_reference', 80)->nullable();
            $table->string('donor_name', 160)->nullable();
            $table->string('sponsored_by', 160)->nullable();
            $table->string('status', 20)->default('PLANNED');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('suhoor_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_id')->index();
            $table->unsignedBigInteger('ramadan_day_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('dormitory_id')->nullable()->index();
            $table->date('event_date');
            $table->time('meal_time')->nullable();
            $table->unsignedInteger('expected_participants')->nullable();
            $table->unsignedInteger('actual_participants')->nullable();
            $table->string('meal_plan_reference', 80)->nullable();
            $table->boolean('catering_required')->default(true);
            $table->string('catering_request_status', 20)->nullable();
            $table->string('status', 20)->default('PLANNED');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('itikaf_programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->string('target_population', 20)->default('STUDENTS');
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('registration_required')->default(true);
            $table->boolean('consent_required')->default(true);
            $table->boolean('meals_provided')->default(false);
            $table->boolean('accommodation_required')->default(false);
            $table->boolean('security_required')->default(false);
            $table->boolean('clinic_required')->default(false);
            $table->unsignedInteger('expected_participants')->nullable();
            $table->string('status', 20)->default('PLANNED');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('itikaf_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_id')->index();
            $table->string('participant_type', 20)->default('STUDENT');
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('person_name', 160)->nullable();
            $table->string('consent_status', 20)->nullable();
            $table->unsignedBigInteger('consent_document_id')->nullable()->index();
            $table->string('emergency_contact_name', 160)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->unsignedBigInteger('accommodation_area_id')->nullable()->index();
            $table->boolean('meal_required')->default(true);
            $table->string('status', 20)->default('REGISTERED');
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('eid_events', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('eid_type', 20)->default('EID_FITR');
            $table->string('hijri_year', 12)->nullable();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->date('event_date');
            $table->time('prayer_time')->nullable();
            $table->unsignedBigInteger('khatib_id')->nullable()->index();
            $table->unsignedBigInteger('imam_id')->nullable()->index();
            $table->unsignedBigInteger('muadzin_id')->nullable()->index();
            $table->string('venue', 160)->nullable();
            $table->unsignedInteger('expected_attendance')->nullable();
            $table->unsignedInteger('actual_attendance')->nullable();
            $table->string('status', 20)->default('PLANNED');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('qurban_programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('eid_event_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('program_year', 12)->nullable();
            $table->json('animal_types')->nullable();
            $table->unsignedInteger('target_beneficiaries')->nullable();
            $table->unsignedInteger('total_animals')->nullable();
            $table->decimal('total_contribution', 18, 2)->nullable();
            $table->string('currency', 10)->default('IDR');
            $table->string('donation_reference', 80)->nullable();
            $table->string('status', 20)->default('PLANNED');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('qurban_animals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_id')->index();
            $table->string('code', 40);
            $table->string('animal_type', 20)->default('GOAT');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->string('donor_name', 160)->nullable();
            $table->string('donor_contact', 30)->nullable();
            $table->decimal('contribution_amount', 14, 2)->nullable();
            $table->string('contribution_reference', 80)->nullable();
            $table->dateTime('sacrificed_at')->nullable();
            $table->string('slaughter_area', 160)->nullable();
            $table->string('status', 20)->default('REGISTERED');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('qurban_distributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_id')->index();
            $table->unsignedBigInteger('animal_id')->nullable()->index();
            $table->string('recipient_type', 20)->default('STUDENT');
            $table->unsignedBigInteger('recipient_id')->nullable()->index();
            $table->string('recipient_name', 160)->nullable();
            $table->string('recipient_category', 40)->nullable();
            $table->unsignedInteger('portion_count')->nullable();
            $table->dateTime('distributed_at')->nullable();
            $table->unsignedBigInteger('distributed_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qurban_distributions');
        Schema::dropIfExists('qurban_animals');
        Schema::dropIfExists('qurban_programs');
        Schema::dropIfExists('eid_events');
        Schema::dropIfExists('itikaf_participants');
        Schema::dropIfExists('itikaf_programs');
        Schema::dropIfExists('suhoor_events');
        Schema::dropIfExists('iftar_events');
        Schema::dropIfExists('tahajjud_sessions');
        Schema::dropIfExists('tarawih_sessions');
        Schema::dropIfExists('ramadan_activities');
        Schema::dropIfExists('ramadan_days');
        Schema::dropIfExists('ramadan_programs');
    }
};
