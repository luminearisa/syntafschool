<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prestasi, sertifikat, portofolio, proyek, dan integrasi (keuangan, peralatan, fasilitas, log).
 *
 * Prestasi adalah entitas tersendiri — bukan sekadar catatan pada program. Satu
 * prestasi dapat berupa individu atau tim, diverifikasi, lalu menjadi dasar
 * sertifikat dan isi portofolio siswa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_achievements', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->foreignId('student_id')->nullable()->constrained('students')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('activity_competition_teams')->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('activity_programs')->nullOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained('activity_competitions')->nullOnDelete();
            $table->foreignId('result_id')->nullable()->constrained('activity_competition_results')->nullOnDelete();
            $table->foreignId('achievement_type_id')->nullable()->constrained('achievement_types')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 32)->default('OTHER')->index(); // ACADEMIC|SPORT|ART|RELIGIOUS|LEADERSHIP|TECHNOLOGY|COMMUNITY|OTHER
            $table->string('ownership', 16)->default('INDIVIDUAL')->index(); // INDIVIDUAL|TEAM
            $table->string('level', 32)->default('SCHOOL')->index();
            $table->string('rank', 32)->nullable();
            $table->date('awarded_date');
            $table->string('organizer')->nullable();
            $table->string('location')->nullable();
            $table->string('evidence_path')->nullable();
            $table->json('evidence_files')->nullable();
            $table->string('verification_status', 24)->default('PENDING')->index(); // PENDING|VERIFIED|REJECTED|REVOKED
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->boolean('counts_for_portfolio')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('activity_achievement_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('achievement_id')->constrained('activity_achievements')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('role', 24)->default('MEMBER');
            $table->boolean('receives_credit')->default(true);
            $table->boolean('certificate_eligible')->default(true);
            $table->timestamps();
            $table->unique(['achievement_id', 'student_id'], 'activity_achievement_members_unique');
        });

        Schema::create('activity_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('certificate_number', 64)->unique();
            $table->string('verification_code', 48)->unique();
            $table->string('type', 32)->index(); // PARTICIPATION|COMPLETION|ACHIEVEMENT|LEADERSHIP|COMPETITION|TRAINING
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('activity_programs')->nullOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('activity_periods')->nullOnDelete();
            $table->foreignId('achievement_id')->nullable()->constrained('activity_achievements')->nullOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained('activity_competitions')->nullOnDelete();
            $table->foreignId('completion_id')->nullable()->constrained('activity_completions')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('issued_date');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('DRAFT')->index(); // DRAFT|ISSUED|REVOKED|EXPIRED
            $table->string('pdf_path')->nullable();
            $table->string('document_reference')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('activity_certificate_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->nullable()->constrained('activity_certificates')->nullOnDelete();
            $table->string('certificate_number', 64)->index();
            $table->string('result', 24); // VALID|NOT_FOUND|REVOKED|EXPIRED
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('verified_at')->useCurrent();
        });

        Schema::create('activity_portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('activity_periods')->nullOnDelete();
            $table->string('scope', 24)->default('PERIOD'); // PERIOD|ALL_TIME
            $table->json('snapshot')->nullable();
            $table->timestamp('generated_at')->useCurrent();
            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamp('public_shared_at')->nullable();
            $table->boolean('public_share_enabled')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('activity_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('activity_periods')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('objective')->nullable();
            $table->string('status', 24)->default('PLANNED')->index(); // PLANNED|ONGOING|COMPLETED|CANCELLED
            $table->date('started_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->string('artifact_path')->nullable();
            $table->json('skills')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('activity_project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('activity_projects')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('role', 24)->default('MEMBER');
            $table->text('contribution')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'student_id'], 'activity_project_members_unique');
        });

        Schema::create('activity_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->nullable()->constrained('activity_programs')->nullOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('activity_registrations')->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('activity_memberships')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('type', 32); // REGISTRATION|MONTHLY|EQUIPMENT|COMPETITION|TRIP|UNIFORM
            $table->string('description');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency', 8)->default('IDR');
            $table->string('status', 24)->default('REQUESTED')->index(); // REQUESTED|INVOICED|PAID|WAIVED|FAILED|CANCELLED
            $table->string('external_reference')->nullable();
            $table->string('idempotency_key', 96)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('settled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['idempotency_key'], 'activity_charges_idempotency_unique');
        });

        Schema::create('activity_equipment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->nullable()->constrained('activity_programs')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('description');
            $table->integer('quantity')->default(1);
            $table->integer('issued_quantity')->default(0);
            $table->integer('returned_quantity')->default(0);
            $table->string('status', 24)->default('REQUESTED')->index(); // REQUESTED|ISSUED|PARTIAL|RETURNED|REJECTED|LOST
            $table->date('due_on')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('idempotency_key', 96)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['idempotency_key'], 'activity_equipment_requests_idempotency_unique');
        });

        Schema::create('activity_facility_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('activity_schedules')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('activity_sessions')->nullOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('status', 24)->default('REQUESTED')->index(); // REQUESTED|CONFIRMED|REJECTED|CANCELLED|CONFLICT
            $table->string('external_reference')->nullable();
            $table->text('conflict_reason')->nullable();
            $table->string('idempotency_key', 96)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['idempotency_key'], 'activity_facility_bookings_idempotency_unique');
            $table->index(['room_id', 'date', 'start_time'], 'activity_facility_bookings_lookup');
        });

        Schema::create('activity_integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('integration', 32)->index(); // FINANCE|INVENTORY|ASSET|FACILITY|LMS|EVENT|TRANSPORTATION|ATTENDANCE|BOARDING|CERTIFICATE|DOCUMENT|NOTIFICATION
            $table->string('action', 48);
            $table->string('subject_type', 96)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->string('status', 24)->default('OK')->index(); // OK|SKIPPED|FAILED
            $table->text('error')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['integration', 'created_at'], 'activity_integration_logs_index');
        });

        Schema::create('activity_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 64)->index();
            $table->string('subject_type', 96)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 64)->nullable();
            $table->foreignId('program_id')->nullable()->constrained('activity_programs')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id'], 'activity_audit_logs_subject_index');
            $table->index(['program_id', 'created_at'], 'activity_audit_logs_program_index');
        });

        Schema::create('activity_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64)->index();
            $table->foreignId('program_id')->nullable()->constrained('activity_programs')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 24)->default('IN_APP');
            $table->string('status', 24)->default('SENT')->index(); // SENT|FAILED|SUPPRESSED|SKIPPED
            $table->string('dedupe_key', 120)->unique();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_notification_logs');
        Schema::dropIfExists('activity_audit_logs');
        Schema::dropIfExists('activity_integration_logs');
        Schema::dropIfExists('activity_facility_bookings');
        Schema::dropIfExists('activity_equipment_requests');
        Schema::dropIfExists('activity_charges');
        Schema::dropIfExists('activity_project_members');
        Schema::dropIfExists('activity_projects');
        Schema::dropIfExists('activity_portfolios');
        Schema::dropIfExists('activity_certificate_verifications');
        Schema::dropIfExists('activity_certificates');
        Schema::dropIfExists('activity_achievement_members');
        Schema::dropIfExists('activity_achievements');
    }
};
