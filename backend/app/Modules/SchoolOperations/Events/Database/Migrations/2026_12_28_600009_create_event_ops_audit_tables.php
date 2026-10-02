<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Event (School Operations Fase 4) — 600009_create_event_ops_audit_tables.
 *
 * Catatan: kolom `_id` diberi indeks; kunci asing keras hanya dipakai pada
 * tabel anak yang memang terikat siklus hidup induknya (cascade), sehingga
 * pengujian lintas modul tidak bergantung urutan penghapusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_change_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('request_code', 50)->unique();
            $t->string('change_type', 40)->index();
            $t->string('title', 200);
            $t->text('reason');
            $t->json('impact_analysis')->nullable();
            $t->integer('affected_sessions')->default(0);
            $t->integer('affected_participants')->default(0);
            $t->integer('affected_catering')->default(0);
            $t->integer('affected_transportation')->default(0);
            $t->integer('affected_facility')->default(0);
            $t->integer('affected_notification')->default(0);
            $t->decimal('cost_impact')->nullable();
            $t->integer('schedule_impact_days')->nullable();
            $t->unsignedBigInteger('requested_by')->nullable()->index();
            $t->dateTime('requested_at')->nullable();
            $t->string('priority', 20)->default('NORMAL');
            $t->json('old_value')->nullable();
            $t->json('new_value')->nullable();
            $t->string('approval_status', 30)->index()->default('PENDING');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->dateTime('applied_at')->nullable();
            $t->integer('resulting_version')->nullable();
            $t->string('status', 30)->index()->default('SUBMITTED');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_recurrences', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->string('recurrence_code', 50)->unique();
            $t->string('name', 200);
            $t->string('frequency', 20)->default('WEEKLY');
            $t->integer('interval')->default(1);
            $t->string('day_of_week', 20)->nullable();
            $t->integer('day_of_month')->nullable();
            $t->integer('month_of_year')->nullable();
            $t->date('start_date')->index();
            $t->date('end_date')->nullable();
            $t->integer('occurrence_limit')->nullable();
            $t->integer('generated_count')->default(0);
            $t->dateTime('last_generated_at')->nullable();
            $t->unsignedBigInteger('template_id')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_occurrences', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('recurrence_id')->index()->constrained('event_recurrences')->cascadeOnDelete();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->date('occurrence_date')->index();
            $t->integer('occurrence_number')->default(1);
            $t->string('status', 20)->index()->default('GENERATED');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_notifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('notification_type', 60)->index();
            $t->string('audience', 40)->index()->default('ALL');
            $t->string('channel', 30)->default('IN_APP');
            $t->string('title', 200);
            $t->text('message')->nullable();
            $t->json('payload')->nullable();
            $t->string('template_code', 60)->nullable();
            $t->integer('recipient_count')->default(0);
            $t->integer('sent_count')->default(0);
            $t->integer('failed_count')->default(0);
            $t->dateTime('scheduled_at')->nullable()->index();
            $t->dateTime('sent_at')->nullable();
            $t->string('provider_reference', 80)->nullable();
            $t->string('status', 30)->index()->default('DRAFT');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_public_pages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('slug', 220)->unique();
            $t->string('title', 200);
            $t->unsignedBigInteger('banner_document_id')->nullable();
            $t->text('description')->nullable();
            $t->json('content_blocks')->nullable();
            $t->json('faq')->nullable();
            $t->string('contact_name', 150)->nullable();
            $t->string('contact_email', 150)->nullable();
            $t->string('contact_phone', 30)->nullable();
            $t->boolean('registration_enabled')->default(false);
            $t->unsignedBigInteger('registration_form_id')->nullable();
            $t->boolean('show_participants')->default(false);
            $t->boolean('show_schedule')->default(true);
            $t->boolean('show_speakers')->default(true);
            $t->string('meta_title', 200)->nullable();
            $t->string('meta_description', 300)->nullable();
            $t->integer('view_count')->default(0);
            $t->boolean('is_published')->index()->default(false);
            $t->dateTime('published_at')->nullable();
            $t->string('status', 20)->index()->default('DRAFT');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_survey_responses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('evaluation_id')->nullable()->index();
            $t->string('respondent_type', 30)->nullable();
            $t->unsignedBigInteger('respondent_id')->nullable();
            $t->json('answers')->nullable();
            $t->integer('nps_score')->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->boolean('is_anonymous')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_mobile_devices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('device_id', 80)->unique();
            $t->string('device_label', 100)->nullable();
            $t->string('platform', 30)->nullable();
            $t->string('mode', 30)->default('COMMITTEE');
            $t->boolean('offline_enabled')->default(false);
            $t->dateTime('last_sync_at')->nullable();
            $t->integer('pending_sync_count')->default(0);
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_sync_batches', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('device_id', 80)->index();
            $t->string('batch_reference', 80)->unique();
            $t->json('payload')->nullable();
            $t->integer('item_count')->default(0);
            $t->integer('applied_count')->default(0);
            $t->integer('conflict_count')->default(0);
            $t->json('conflict_detail')->nullable();
            $t->string('resolution', 30)->default('SERVER_WINS');
            $t->unsignedBigInteger('synced_by')->nullable();
            $t->dateTime('synced_at')->nullable();
            $t->string('status', 30)->index()->default('PENDING');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_audit_logs', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->unsignedBigInteger('actor_id')->nullable()->index();
            $t->string('actor_name', 150)->nullable();
            $t->string('actor_role', 60)->nullable();
            $t->string('action', 80)->index();
            $t->string('entity_type', 80)->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->json('old_value')->nullable();
            $t->json('new_value')->nullable();
            $t->text('reason')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->unsignedBigInteger('campus_id')->nullable();
            $t->dateTime('created_at')->index();
            $t->softDeletes();
        });
        Schema::create('event_domain_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->string('event_name', 100)->index();
            $t->string('aggregate_type', 80)->nullable();
            $t->unsignedBigInteger('aggregate_id')->nullable()->index();
            $t->json('payload')->nullable();
            $t->json('channels')->nullable();
            $t->boolean('published')->index()->default(false);
            $t->dateTime('published_at')->nullable();
            $t->integer('consumer_count')->default(0);
            $t->dateTime('created_at')->index();
            $t->softDeletes();
        });
        Schema::create('event_idempotency_keys', function (Blueprint $t): void {
            $t->id();
            $t->string('idempotency_key', 120)->unique();
            $t->string('scope', 60)->index();
            $t->string('source', 30)->default('MANUAL');
            $t->string('external_reference', 120)->nullable();
            $t->string('device_id', 80)->nullable();
            $t->dateTime('processed_at')->nullable();
            $t->dateTime('created_at')->index();
            $t->softDeletes();
        });
        Schema::create('event_import_batches', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->string('batch_code', 50)->unique();
            $t->string('import_type', 40)->index();
            $t->string('file_name', 255)->nullable();
            $t->string('file_path', 255)->nullable();
            $t->integer('total_rows')->default(0);
            $t->integer('valid_rows')->default(0);
            $t->integer('invalid_rows')->default(0);
            $t->integer('imported_rows')->default(0);
            $t->integer('skipped_rows')->default(0);
            $t->json('validation_errors')->nullable();
            $t->string('status', 30)->index()->default('DRAFT');
            $t->unsignedBigInteger('imported_by')->nullable()->index();
            $t->dateTime('imported_at')->nullable();
            $t->dateTime('rolled_back_at')->nullable();
            $t->text('rollback_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_import_rows', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('batch_id')->index()->constrained('event_import_batches')->cascadeOnDelete();
            $t->integer('row_number');
            $t->json('payload')->nullable();
            $t->json('normalized')->nullable();
            $t->boolean('is_valid')->index()->default(true);
            $t->json('errors')->nullable();
            $t->string('action', 20)->default('IMPORT');
            $t->unsignedBigInteger('applied_id')->nullable();
            $t->string('status', 20)->index()->default('PENDING');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_import_rows');
        Schema::dropIfExists('event_import_batches');
        Schema::dropIfExists('event_idempotency_keys');
        Schema::dropIfExists('event_domain_events');
        Schema::dropIfExists('event_audit_logs');
        Schema::dropIfExists('event_sync_batches');
        Schema::dropIfExists('event_mobile_devices');
        Schema::dropIfExists('event_survey_responses');
        Schema::dropIfExists('event_public_pages');
        Schema::dropIfExists('event_notifications');
        Schema::dropIfExists('event_occurrences');
        Schema::dropIfExists('event_recurrences');
        Schema::dropIfExists('event_change_requests');
    }
};
