<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kegiatan keagamaan, sesi, pendaftaran, peserta, presensi, kajian, referensi kitab, jenis penilaian, penilaian, dan profil peserta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('religious_activities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('title', 200);
            $table->string('activity_type', 30)->default('KAJIAN');
            $table->text('description')->nullable();
            $table->string('category', 60)->nullable();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('location', 160)->nullable();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->unsignedBigInteger('instructor_personnel_id')->nullable()->index();
            $table->string('instructor_name', 160)->nullable();
            $table->string('target_population', 30)->default('ALL_STUDENTS');
            $table->json('target_filter')->nullable();
            $table->string('registration_mode', 20)->default('OPEN');
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('waitlist_enabled')->default(true);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('recurring')->default(false);
            $table->json('recurrence_rule')->nullable();
            $table->boolean('assessment_enabled')->default(false);
            $table->boolean('certificate_enabled')->default(false);
            $table->unsignedBigInteger('lms_course_id')->nullable()->index();
            $table->unsignedBigInteger('linked_activity_id')->nullable()->index();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_activity_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_id')->index();
            $table->unsignedInteger('session_number')->default(1);
            $table->string('title', 200)->nullable();
            $table->date('session_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('location', 160)->nullable();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->unsignedBigInteger('instructor_personnel_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->string('topic', 200)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('registered_count')->default(0);
            $table->unsignedInteger('attended_count')->default(0);
            $table->string('status', 20)->default('SCHEDULED');
            $table->dateTime('attendance_opened_at')->nullable();
            $table->dateTime('attendance_closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_activity_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_id')->index();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            $table->string('participant_type', 20)->default('STUDENT');
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('external_name', 160)->nullable();
            $table->string('external_contact', 40)->nullable();
            $table->dateTime('registered_at')->nullable();
            $table->string('registration_channel', 20)->default('ONLINE');
            $table->string('status', 20)->default('REGISTERED');
            $table->unsignedInteger('waitlist_position')->nullable();
            $table->string('approval_status', 20)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->string('source_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_activity_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_id')->index();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            $table->unsignedBigInteger('registration_id')->nullable()->index();
            $table->string('participant_type', 20)->default('STUDENT');
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('person_name', 160)->nullable();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('dormitory_id')->nullable()->index();
            $table->string('grade_level', 30)->nullable();
            $table->string('participant_role', 30)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->dateTime('joined_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('completion_status', 20)->nullable();
            $table->decimal('assessment_score', 5, 2)->nullable();
            $table->string('halaqah_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_activity_attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('participant_id')->index();
            $table->unsignedBigInteger('activity_id')->index();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            $table->date('attendance_date');
            $table->string('status', 20)->default('PRESENT');
            $table->string('method', 20)->default('MANUAL');
            $table->unsignedBigInteger('recorded_by')->nullable()->index();
            $table->dateTime('recorded_at')->nullable();
            $table->unsignedInteger('minutes_late')->nullable();
            $table->string('external_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_study_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_id')->nullable()->index();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('title', 200);
            $table->string('topic', 200)->nullable();
            $table->string('speaker_name', 160)->nullable();
            $table->unsignedBigInteger('personnel_id')->nullable()->index();
            $table->string('book_title', 200)->nullable();
            $table->string('book_author', 120)->nullable();
            $table->string('chapter', 80)->nullable();
            $table->unsignedInteger('page_from')->nullable();
            $table->unsignedInteger('page_to')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable()->index();
            $table->unsignedInteger('session_number')->default(1);
            $table->date('session_date');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('location', 160)->nullable();
            $table->string('target_group', 120)->nullable();
            $table->string('language', 20)->nullable();
            $table->string('status', 20)->default('PLANNED');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_study_references', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('reference_type', 20)->default('BOOK');
            $table->string('author', 160)->nullable();
            $table->string('publisher', 160)->nullable();
            $table->string('isbn', 40)->nullable();
            $table->unsignedBigInteger('library_item_id')->nullable()->index();
            $table->string('category', 60)->nullable();
            $table->string('language', 20)->nullable();
            $table->string('edition', 40)->nullable();
            $table->unsignedInteger('publication_year')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('file_document_id')->nullable()->index();
            $table->json('tags')->nullable();
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_assessment_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('category', 20)->default('MEMORIZATION');
            $table->string('scale_type', 20)->default('SCORE');
            $table->decimal('max_score', 5, 2)->nullable();
            $table->json('components')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->json('rubric')->nullable();
            $table->boolean('requires_supervisor')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->boolean('active')->default(true);
            $table->json('config_data')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_activity_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_id')->nullable()->index();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            $table->unsignedBigInteger('participant_id')->nullable()->index();
            $table->unsignedBigInteger('assessment_type_id')->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('assessor_id')->nullable()->index();
            $table->string('assessor_name', 160)->nullable();
            $table->date('assessment_date');
            $table->decimal('score', 5, 2)->nullable();
            $table->decimal('max_score', 5, 2)->nullable();
            $table->string('grade_label', 20)->nullable();
            $table->string('level_label', 40)->nullable();
            $table->json('components')->nullable();
            $table->string('result', 20)->default('PASS');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('evidence_document_id')->nullable()->index();
            $table->string('status', 20)->default('FINAL');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_participant_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('person_type', 20)->default('STUDENT');
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('religious_level', 40)->nullable();
            $table->string('quran_level', 40)->nullable();
            $table->string('halaqah_reference', 80)->nullable();
            $table->string('tahfiz_reference', 80)->nullable();
            $table->json('interest_areas')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('religious_participant_profiles');
        Schema::dropIfExists('religious_activity_assessments');
        Schema::dropIfExists('religious_assessment_types');
        Schema::dropIfExists('religious_study_references');
        Schema::dropIfExists('religious_study_sessions');
        Schema::dropIfExists('religious_activity_attendance');
        Schema::dropIfExists('religious_activity_participants');
        Schema::dropIfExists('religious_activity_registrations');
        Schema::dropIfExists('religious_activity_sessions');
        Schema::dropIfExists('religious_activities');
    }
};
