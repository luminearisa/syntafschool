<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog aktivitas: kategori, program, periode, tujuan, keterampilan, peran, requirement, registrasi, seleksi.
 *
 * Inilah "katalog" Student Development Platform: kategori berjenjang, program
 * (ekstrakurikuler, klub, organisasi, kompetisi), periode akademik, tujuan
 * perkembangan, keterampilan yang dibina, dan aturan penerimaan anggota.
 *
 * Aturan penerimaan (requirement) sengaja disimpan sebagai baris ber-`type` +
 * `operator` + `value` JSON — bukan kolom tetap — supaya sekolah dapat menambah
 * syarat baru (mis. rekomendasi guru, tes fisik) tanpa migrasi baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('activity_categories')->nullOnDelete();
            $table->string('icon')->nullable();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('activity_objectives', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 32)->default('DEVELOPMENT'); // DEVELOPMENT|SKILL|COMPETENCY
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('activity_skills', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 32)->default('GENERAL');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('activity_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('can_lead')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('activity_periods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name');
            $table->string('type', 24)->default('SEMESTER'); // ACADEMIC_YEAR|SEMESTER|TERM|SEASON
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained('semesters')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->date('registration_opens')->nullable();
            $table->date('registration_closes')->nullable();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->boolean('is_current')->default(false)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('slug')->nullable()->index();
            $table->foreignId('category_id')->constrained('activity_categories')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->text('objectives_summary')->nullable();
            $table->string('type', 32)->default('EXTRACURRICULAR')->index();
            $table->string('delivery_type', 24)->default('RECURRING')->index(); // RECURRING|SEASONAL|ONE_TIME|PROJECT_BASED|COMPETITIVE|ORGANIZATION
            $table->string('level', 32)->nullable()->index(); // education level code
            $table->string('gender', 16)->default('ANY'); // ANY|MALE|FEMALE
            $table->string('status', 24)->default('DRAFT')->index();
            $table->foreignId('period_id')->nullable()->constrained('activity_periods')->nullOnDelete();
            $table->foreignId('coordinator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('advisor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('coach_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->integer('minimum_capacity')->nullable();
            $table->integer('maximum_capacity')->nullable();
            $table->integer('minimum_grade_level')->nullable();
            $table->integer('maximum_grade_level')->nullable();
            $table->string('selection_method', 24)->default('OPEN')->index();
            $table->date('registration_opens')->nullable();
            $table->date('registration_closes')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('requires_parent_consent')->default(false);
            $table->boolean('requires_payment')->default(false);
            $table->decimal('fee_amount', 15, 2)->default(0);
            $table->string('fee_currency', 8)->default('IDR');
            $table->integer('minimum_attendance_percentage')->nullable();
            $table->json('completion_rules')->nullable();
            $table->json('certificate_rules')->nullable();
            $table->foreignId('lms_course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('activity_program_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('activity_skills')->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->decimal('weight', 5, 2)->default(1);
            $table->timestamps();
            $table->unique(['program_id', 'skill_id'], 'activity_program_skills_unique');
        });

        Schema::create('activity_program_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('objective_id')->constrained('activity_objectives')->cascadeOnDelete();
            $table->string('target_level', 32)->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'objective_id'], 'activity_program_objectives_unique');
        });

        Schema::create('activity_registration_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->string('type', 48); // AGE|GRADE|PROGRAM|GENDER|CAPACITY|PREREQUISITE|SKILL|ATTENDANCE|RECOMMENDATION|AUDITION|TRIAL|CONSENT|ACADEMIC_STANDING|BOARDING
            $table->string('operator', 16)->default('GTE'); // EQ|NEQ|GT|GTE|LT|LTE|IN|NOT_IN|EXISTS
            $table->json('value')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->string('message', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('activity_program_coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('role', 32)->default('COACH'); // ADVISOR|COACH|MENTOR|ASSISTANT
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('activity_periods')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('status', 24)->default('DRAFT')->index();
            $table->string('source', 24)->default('STUDENT'); // STUDENT|PARENT|ADMIN|IMPORT
            $table->json('requirement_snapshot')->nullable();
            $table->json('validation_result')->nullable();
            $table->integer('waitlist_position')->nullable();
            $table->integer('priority')->default(0);
            $table->string('idempotency_key', 96)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->text('motivation')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['program_id', 'period_id', 'student_id'], 'activity_registrations_unique_member');
            $table->unique(['idempotency_key'], 'activity_registrations_idempotency_unique');
        });

        Schema::create('activity_registration_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('activity_registrations')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['registration_id', 'created_at'], 'activity_reg_histories_index');
        });

        Schema::create('activity_selection_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('activity_registrations')->cascadeOnDelete();
            $table->string('stage', 24); // TRIAL|AUDITION|INTERVIEW|ASSESSMENT|SCREENING
            $table->decimal('score', 8, 2)->nullable();
            $table->integer('rank')->nullable();
            $table->string('decision', 24)->default('PENDING'); // PENDING|SELECTED|WAITLISTED|REJECTED
            $table->text('notes')->nullable();
            $table->json('criteria')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['registration_id', 'stage'], 'activity_selection_stage_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_selection_results');
        Schema::dropIfExists('activity_registration_histories');
        Schema::dropIfExists('activity_registrations');
        Schema::dropIfExists('activity_program_coaches');
        Schema::dropIfExists('activity_registration_requirements');
        Schema::dropIfExists('activity_program_objectives');
        Schema::dropIfExists('activity_program_skills');
        Schema::dropIfExists('activity_programs');
        Schema::dropIfExists('activity_periods');
        Schema::dropIfExists('activity_roles');
        Schema::dropIfExists('activity_skills');
        Schema::dropIfExists('activity_objectives');
        Schema::dropIfExists('activity_categories');
    }
};
