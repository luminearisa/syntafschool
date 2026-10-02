<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Event (School Operations Fase 4) — 600008_create_event_attendance_documentation_tables.
 *
 * Catatan: kolom `_id` diberi indeks; kunci asing keras hanya dipakai pada
 * tabel anak yang memang terikat siklus hidup induknya (cascade), sehingga
 * pengujian lintas modul tidak bergantung urutan penghapusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_checkins', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->unsignedBigInteger('guest_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->string('checkpoint', 50)->nullable();
            $t->string('direction', 20)->index()->default('IN');
            $t->string('method', 30)->index()->default('MANUAL');
            $t->string('status', 30)->index()->default('CHECKED_IN');
            $t->unsignedBigInteger('token_id')->nullable();
            $t->boolean('token_valid')->nullable();
            $t->dateTime('checked_at')->index();
            $t->string('device_id', 80)->nullable();
            $t->string('device_label', 80)->nullable();
            $t->string('location', 255)->nullable();
            $t->unsignedBigInteger('operator_id')->nullable()->index();
            $t->string('operator_name', 150)->nullable();
            $t->string('offline_reference', 80)->nullable()->index();
            $t->dateTime('synced_at')->nullable();
            $t->string('sync_source', 20)->default('ONLINE');
            $t->boolean('is_duplicate')->default(false);
            $t->text('note')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_session_attendance', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->foreignId('session_id')->index()->constrained('event_sessions')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->string('status', 30)->index()->default('PRESENT');
            $t->string('method', 30)->default('MANUAL');
            $t->dateTime('check_in_at')->nullable();
            $t->dateTime('check_out_at')->nullable();
            $t->integer('duration_minutes')->nullable();
            $t->string('device_id', 80)->nullable();
            $t->unsignedBigInteger('operator_id')->nullable()->index();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->boolean('is_mandatory')->default(true);
            $t->boolean('counts_for_eligibility')->default(true);
            $t->string('offline_reference', 80)->nullable();
            $t->dateTime('synced_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_attendance_adjustments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->unsignedBigInteger('checkin_id')->nullable()->index();
            $t->string('field_changed', 40);
            $t->string('old_value', 255)->nullable();
            $t->string('new_value', 255)->nullable();
            $t->text('reason');
            $t->unsignedBigInteger('adjusted_by')->nullable()->index();
            $t->dateTime('adjusted_at')->nullable();
            $t->string('status', 20)->index()->default('APPLIED');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_media', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->string('media_type', 20)->index()->default('PHOTO');
            $t->string('title', 200)->nullable();
            $t->text('caption')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->string('file_path', 255)->nullable();
            $t->string('thumbnail_path', 255)->nullable();
            $t->string('mime_type', 80)->nullable();
            $t->integer('file_size')->nullable();
            $t->string('photographer_name', 150)->nullable();
            $t->unsignedBigInteger('photographer_id')->nullable()->index();
            $t->dateTime('taken_at')->nullable();
            $t->string('location', 255)->nullable();
            $t->string('visibility', 20)->index()->default('INTERNAL');
            $t->json('participant_ids')->nullable();
            $t->string('consent_status', 30)->index()->default('PHOTO_CONSENT_REQUIRED');
            $t->boolean('consent_checked')->default(false);
            $t->dateTime('published_at')->nullable();
            $t->integer('sort_order')->default(0);
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('document_id')->nullable()->index();
            $t->string('document_type', 40)->index();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('file_path', 255)->nullable();
            $t->string('mime_type', 80)->nullable();
            $t->integer('file_size')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('is_public')->default(false);
            $t->string('visibility', 20)->default('INTERNAL');
            $t->unsignedBigInteger('uploaded_by')->nullable()->index();
            $t->dateTime('archived_at')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_certificates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->string('certificate_type', 30)->index()->default('PARTICIPANT');
            $t->string('recipient_type', 30)->nullable();
            $t->unsignedBigInteger('recipient_id')->nullable();
            $t->string('recipient_name', 150);
            $t->string('certificate_number', 60)->unique();
            $t->string('template_code', 60)->nullable();
            $t->string('eligibility_rule', 60)->nullable();
            $t->json('eligibility_snapshot')->nullable();
            $t->boolean('eligible')->index()->default(false);
            $t->integer('sessions_attended')->nullable();
            $t->integer('sessions_required')->nullable();
            $t->decimal('attendance_percent')->nullable();
            $t->dateTime('issued_at')->nullable();
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->string('provider_reference', 80)->nullable();
            $t->string('verification_code', 80)->nullable()->unique();
            $t->dateTime('revoked_at')->nullable();
            $t->text('revoke_reason')->nullable();
            $t->string('status', 30)->index()->default('PENDING');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_evaluations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('evaluation_type', 30)->index()->default('PARTICIPANT_SURVEY');
            $t->string('respondent_type', 30)->nullable();
            $t->unsignedBigInteger('respondent_id')->nullable();
            $t->string('respondent_name', 150)->nullable();
            $t->integer('overall_rating')->nullable();
            $t->json('rating_breakdown')->nullable();
            $t->text('what_went_well')->nullable();
            $t->text('what_to_improve')->nullable();
            $t->text('recommendation')->nullable();
            $t->boolean('would_recommend')->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->unsignedBigInteger('survey_response_id')->nullable();
            $t->boolean('is_anonymous')->default(false);
            $t->string('status', 20)->index()->default('SUBMITTED');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_kpis', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('kpi_code', 60);
            $t->string('name', 200);
            $t->string('category', 40)->nullable();
            $t->string('unit', 30)->nullable();
            $t->decimal('target_value')->nullable();
            $t->decimal('actual_value')->nullable();
            $t->decimal('variance')->nullable();
            $t->decimal('achievement_percent')->nullable();
            $t->string('direction', 10)->default('HIGHER_BETTER');
            $t->integer('weight')->default(1);
            $t->string('status', 20)->index()->default('PENDING');
            $t->dateTime('measured_at')->nullable();
            $t->unsignedBigInteger('measured_by')->nullable();
            $t->integer('sort_order')->default(0);
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_reports', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('report_code', 50)->unique();
            $t->string('report_type', 30)->index()->default('FINAL');
            $t->string('title', 200);
            $t->text('executive_summary')->nullable();
            $t->text('objectives_summary')->nullable();
            $t->text('participants_summary')->nullable();
            $t->text('program_summary')->nullable();
            $t->text('attendance_summary')->nullable();
            $t->text('financial_summary')->nullable();
            $t->text('resources_summary')->nullable();
            $t->text('incidents_summary')->nullable();
            $t->text('documentation_summary')->nullable();
            $t->text('evaluation_summary')->nullable();
            $t->text('recommendations')->nullable();
            $t->json('snapshot')->nullable();
            $t->unsignedBigInteger('generated_by')->nullable()->index();
            $t->dateTime('generated_at')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->boolean('is_published')->default(false);
            $t->dateTime('published_at')->nullable();
            $t->string('status', 20)->index()->default('DRAFT');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reports');
        Schema::dropIfExists('event_kpis');
        Schema::dropIfExists('event_evaluations');
        Schema::dropIfExists('event_certificates');
        Schema::dropIfExists('event_documents');
        Schema::dropIfExists('event_media');
        Schema::dropIfExists('event_attendance_adjustments');
        Schema::dropIfExists('event_session_attendance');
        Schema::dropIfExists('event_checkins');
    }
};
