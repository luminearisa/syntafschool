<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel Phase 5 (§6, §12, §14, §22, §23, §24, §42).
 *
 * Semua tabel di sini bersifat **tambahan**: tidak ada kolom Phase 1–4 yang
 * diubah atau dihapus, sehingga penerapan migrasi ini aman pada basis data
 * yang sudah berjalan dan tidak menghilangkan bukti belajar apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- §23 Audit trail ----------
        Schema::create('lms_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name', 40)->default('lms');
            $table->string('action', 60);                        // course.published, grade.released, dst.
            $table->string('entity_type', 120);                  // kelas model atau alias pendek
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('course_id')->nullable(); // mempermudah penyaringan per course
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 150)->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id'], 'lms_audit_entity_idx');
            $table->index(['course_id', 'created_at'], 'lms_audit_course_idx');
            $table->index(['action', 'created_at'], 'lms_audit_action_idx');
            $table->index(['actor_id', 'created_at'], 'lms_audit_actor_idx');
            $table->index('correlation_id');
        });

        // ---------- §10 Notification log (idempotent + auditable) ----------
        Schema::create('lms_notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('dedupe_key', 190)->unique();
            $table->string('event', 60)->index();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('PENDING');     // PENDING|SENT|FAILED
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // ---------- §6 Grade sync: antrean + riwayat percobaan ----------
        Schema::table('lms_grade_syncs', function (Blueprint $table): void {
            if (! Schema::hasColumn('lms_grade_syncs', 'idempotency_key')) {
                $table->string('idempotency_key', 190)->nullable()->unique();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->string('cancel_reason', 190)->nullable();
                $table->json('compare_snapshot')->nullable();
                $table->index(['status', 'next_attempt_at'], 'lms_grade_syncs_queue_idx');
            }
        });

        Schema::create('lms_grade_sync_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grade_sync_id')->constrained('lms_grade_syncs')->cascadeOnDelete();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->string('status', 20);                          // PENDING|PROCESSING|SYNCED|FAILED|RETRYING|CANCELLED
            $table->string('idempotency_key', 190)->nullable()->unique();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['grade_sync_id', 'attempt_no']);
        });

        // ---------- §12 Content interoperability (SCORM 1.2 / 2004) ----------
        Schema::create('lms_scorm_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 190);
            $table->string('version', 20)->default('SCORM_12');     // SCORM_12|SCORM_2004
            $table->string('identifier', 190)->nullable();
            $table->string('manifest_path')->nullable();
            $table->string('package_path')->nullable();
            $table->string('storage_disk', 40)->nullable();
            $table->string('status', 20)->default('UPLOADED');      // UPLOADED|VALIDATED|INVALID|IMPORTED|ARCHIVED
            $table->json('manifest')->nullable();
            $table->json('validation')->nullable();
            $table->unsignedInteger('organizations_count')->default(0);
            $table->unsignedInteger('sco_count')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('lms_scorm_organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scorm_package_id')->constrained('lms_scorm_packages')->cascadeOnDelete();
            $table->string('identifier', 190);
            $table->string('title', 190)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['scorm_package_id', 'identifier']);
        });

        Schema::create('lms_scorm_scoes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scorm_package_id')->constrained('lms_scorm_packages')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('lms_scorm_organizations')->nullOnDelete();
            $table->string('identifier', 190);
            $table->string('title', 190)->nullable();
            $table->string('launch_path')->nullable();
            $table->string('scorm_type', 40)->nullable();
            $table->json('parameters')->nullable();
            $table->timestamps();

            $table->index(['scorm_package_id', 'identifier']);
        });

        Schema::create('lms_scorm_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scorm_package_id')->constrained('lms_scorm_packages')->cascadeOnDelete();
            $table->foreignId('sco_id')->nullable()->constrained('lms_scorm_scoes')->nullOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->string('status', 20)->default('NOT_ATTEMPTED');
            $table->string('completion_status', 20)->nullable();
            $table->string('success_status', 20)->nullable();
            $table->decimal('score_raw', 8, 2)->nullable();
            $table->decimal('score_min', 8, 2)->nullable();
            $table->decimal('score_max', 8, 2)->nullable();
            $table->decimal('score_scaled', 8, 4)->nullable();
            $table->unsignedInteger('total_time_seconds')->default(0);
            $table->string('session_time', 30)->nullable();
            $table->string('suspend_data_hash', 64)->nullable();
            $table->json('cmi')->nullable();                        // state CMI mutakhir (untuk resume)
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('last_commit_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'sco_id'], 'lms_scorm_attempt_user_sco_unique');
            $table->index(['course_id', 'user_id']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('lms_scorm_tracking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attempt_id')->constrained('lms_scorm_attempts')->cascadeOnDelete();
            $table->string('element', 120);
            $table->string('value', 255)->nullable();
            $table->string('idempotency_key', 190)->nullable()->unique();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['attempt_id', 'occurred_at']);
        });

        // ---------- §14 Import course (validate → preview → resolve → import → verify) ----------
        Schema::create('lms_course_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 190)->nullable()->unique();
            $table->string('status', 20)->default('UPLOADED');      // UPLOADED|VALIDATED|INVALID|IMPORTING|IMPORTED|FAILED
            $table->string('conflict_strategy', 20)->default('SKIP'); // SKIP|REPLACE|DUPLICATE|MERGE
            $table->string('source_name', 190)->nullable();
            $table->string('storage_path')->nullable();
            $table->foreignId('target_course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('lms_course_templates')->nullOnDelete();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->json('summary')->nullable();
            $table->json('validation')->nullable();
            $table->json('preview')->nullable();
            $table->json('conflicts')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // ---------- §22 Report center ----------
        Schema::create('lms_report_exports', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 190)->nullable()->unique();
            $table->string('report_key', 60);
            $table->string('format', 10)->default('CSV');           // CSV|XLSX|PDF
            $table->string('status', 20)->default('PENDING');        // PENDING|PROCESSING|READY|FAILED
            $table->json('filters')->nullable();
            $table->string('file_path')->nullable();
            $table->string('storage_disk', 40)->nullable();
            $table->unsignedInteger('rows_count')->default(0);
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['report_key', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // ---------- §24 Integritas data ----------
        Schema::create('lms_integrity_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 40)->default('all');
            $table->string('status', 20)->default('OK');             // OK|WARNINGS|CRITICAL
            $table->unsignedInteger('checks_run')->default(0);
            $table->unsignedInteger('issues_found')->default(0);
            $table->json('findings')->nullable();
            $table->unsignedBigInteger('run_by')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });

        // ---------- §31 indeks tambahan (query panas Phase 4) ----------
        Schema::table('lms_learning_events', function (Blueprint $table): void {
            $table->index(['user_id', 'occurred_at'], 'lms_events_user_time_idx');
            $table->index(['course_id', 'occurred_at'], 'lms_events_course_time_idx');
        });

        Schema::table('lms_live_sessions', function (Blueprint $table): void {
            $table->index(['course_id', 'start_at'], 'lms_live_course_start_idx');
            $table->index(['status', 'start_at'], 'lms_live_status_start_idx');
        });

        Schema::table('lms_certificates', function (Blueprint $table): void {
            $table->index(['course_id', 'status'], 'lms_cert_course_status_idx');
        });

        Schema::table('lms_course_reviews', function (Blueprint $table): void {
            $table->index(['course_id', 'status'], 'lms_reviews_course_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lms_course_reviews', function (Blueprint $table): void {
            $table->dropIndex('lms_reviews_course_status_idx');
        });

        Schema::table('lms_certificates', function (Blueprint $table): void {
            $table->dropIndex('lms_cert_course_status_idx');
        });

        Schema::table('lms_live_sessions', function (Blueprint $table): void {
            $table->dropIndex('lms_live_course_start_idx');
            $table->dropIndex('lms_live_status_start_idx');
        });

        Schema::table('lms_learning_events', function (Blueprint $table): void {
            $table->dropIndex('lms_events_user_time_idx');
            $table->dropIndex('lms_events_course_time_idx');
        });

        Schema::dropIfExists('lms_integrity_runs');
        Schema::dropIfExists('lms_report_exports');
        Schema::dropIfExists('lms_course_imports');
        Schema::dropIfExists('lms_scorm_tracking_events');
        Schema::dropIfExists('lms_scorm_attempts');
        Schema::dropIfExists('lms_scorm_scoes');
        Schema::dropIfExists('lms_scorm_organizations');
        Schema::dropIfExists('lms_scorm_packages');
        Schema::dropIfExists('lms_grade_sync_attempts');

        Schema::table('lms_grade_syncs', function (Blueprint $table): void {
            $table->dropIndex('lms_grade_syncs_queue_idx');
            $table->dropColumn([
                'idempotency_key', 'queued_at', 'next_attempt_at', 'cancelled_at',
                'cancelled_by', 'cancel_reason', 'compare_snapshot',
            ]);
        });

        Schema::dropIfExists('lms_notification_logs');
        Schema::dropIfExists('lms_audit_logs');
    }
};
