<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Event (School Operations Fase 4) — 600004_create_event_registration_tables.
 *
 * Catatan: kolom `_id` diberi indeks; kunci asing keras hanya dipakai pada
 * tabel anak yang memang terikat siklus hidup induknya (cascade), sehingga
 * pengujian lintas modul tidak bergantung urutan penghapusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registration_fields', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('field_code', 50);
            $t->string('label', 200);
            $t->string('field_type', 30)->default('TEXT');
            $t->string('placeholder', 200)->nullable();
            $t->string('help_text', 500)->nullable();
            $t->json('options')->nullable();
            $t->string('validation_rules', 255)->nullable();
            $t->boolean('is_required')->default(false);
            $t->boolean('is_system')->default(false);
            $t->boolean('is_public')->default(true);
            $t->integer('sort_order')->default(0);
            $t->string('default_value', 255)->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->string('event_id_code', 80)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_registrations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('registration_code', 50)->unique();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->string('registration_mode', 30)->index()->default('OPEN');
            $t->string('source', 30)->default('PORTAL');
            $t->string('registrant_type', 30)->index()->default('STUDENT');
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->unsignedBigInteger('parent_id')->nullable()->index();
            $t->string('external_name', 150)->nullable();
            $t->string('external_email', 150)->nullable();
            $t->string('external_phone', 30)->nullable();
            $t->string('external_organization', 200)->nullable();
            $t->json('registration_data')->nullable();
            $t->string('status', 30)->index()->default('PENDING');
            $t->integer('waitlist_position')->nullable();
            $t->integer('priority_score')->default(0);
            $t->boolean('manual_priority')->default(false);
            $t->dateTime('submitted_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('rejected_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->boolean('consent_required')->default(false);
            $t->string('consent_status', 30)->index()->default('NOT_REQUIRED');
            $t->string('payment_status', 30)->index()->default('NOT_REQUIRED');
            $t->string('payment_reference', 80)->nullable();
            $t->string('ticket_code', 60)->nullable()->unique();
            $t->dateTime('checked_in_at')->nullable();
            $t->dateTime('checked_out_at')->nullable();
            $t->decimal('selection_score')->nullable();
            $t->string('selection_stage', 30)->nullable();
            $t->text('status_note')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_registration_answers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('registration_id')->index()->constrained('event_registrations')->cascadeOnDelete();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->foreignId('field_id')->index()->constrained('event_registration_fields')->cascadeOnDelete();
            $t->string('field_code', 50)->nullable();
            $t->text('value_text')->nullable();
            $t->decimal('value_number')->nullable();
            $t->date('value_date')->nullable();
            $t->json('value_json')->nullable();
            $t->unsignedBigInteger('file_document_id')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_participants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->string('participant_code', 60)->nullable()->index();
            $t->string('participant_type', 30)->index()->default('STUDENT');
            $t->string('source_type', 30)->nullable();
            $t->unsignedBigInteger('source_id')->nullable()->index();
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->unsignedBigInteger('parent_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->string('name', 150);
            $t->string('email', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('organization', 200)->nullable();
            $t->string('category', 50)->nullable();
            $t->string('grade_level', 50)->nullable();
            $t->string('class_name', 100)->nullable();
            $t->string('program_name', 100)->nullable();
            $t->unsignedBigInteger('dormitory_id')->nullable()->index();
            $t->string('dietary_requirement', 150)->nullable();
            $t->text('medical_note')->nullable();
            $t->boolean('needs_transportation')->default(false);
            $t->boolean('needs_accommodation')->default(false);
            $t->string('emergency_contact_name', 150)->nullable();
            $t->string('emergency_contact_phone', 30)->nullable();
            $t->string('tshirt_size', 10)->nullable();
            $t->string('badge_type', 30)->index()->default('STUDENT');
            $t->unsignedBigInteger('badge_id')->nullable();
            $t->string('qr_token', 80)->nullable()->unique();
            $t->string('attendance_status', 30)->index()->default('REGISTERED');
            $t->dateTime('check_in_at')->nullable();
            $t->dateTime('check_out_at')->nullable();
            $t->integer('sessions_attended')->default(0);
            $t->integer('sessions_required')->nullable();
            $t->string('photo_consent_status', 30)->index()->default('PHOTO_CONSENT_REQUIRED');
            $t->boolean('certificate_eligible')->index()->default(false);
            $t->unsignedBigInteger('certificate_id')->nullable();
            $t->boolean('is_committee')->default(false);
            $t->boolean('is_volunteer')->default(false);
            $t->boolean('is_vip')->default(false);
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_waitlists', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->string('participant_name', 150)->nullable();
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->integer('position')->index()->default(0);
            $t->string('priority', 20)->index()->default('NORMAL');
            $t->integer('priority_score')->default(0);
            $t->dateTime('registered_at')->nullable();
            $t->dateTime('promoted_at')->nullable();
            $t->unsignedBigInteger('promoted_to_registration_id')->nullable();
            $t->string('status', 30)->index()->default('WAITING');
            $t->string('reason', 255)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_consents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('parent_id')->nullable()->index();
            $t->string('consent_type', 30)->index()->default('PARENT');
            $t->integer('consent_version')->default(1);
            $t->text('consent_text')->nullable();
            $t->json('context_snapshot')->nullable();
            $t->dateTime('requested_at')->nullable();
            $t->dateTime('responded_at')->nullable();
            $t->string('status', 30)->index()->default('PENDING');
            $t->string('response', 20)->nullable();
            $t->text('decline_reason')->nullable();
            $t->unsignedBigInteger('responded_by')->nullable();
            $t->string('responded_by_name', 150)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->boolean('is_required')->default(true);
            $t->unsignedBigInteger('notification_id')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_badges', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('guest_id')->nullable()->index();
            $t->string('badge_type', 30)->index()->default('STUDENT');
            $t->string('badge_code', 60)->unique();
            $t->string('holder_name', 150);
            $t->string('category', 50)->nullable();
            $t->string('organization', 200)->nullable();
            $t->string('event_name', 200)->nullable();
            $t->string('qr_token', 80)->nullable()->unique();
            $t->dateTime('printed_at')->nullable();
            $t->integer('print_count')->default(0);
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_registration_tokens', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->unsignedBigInteger('guest_id')->nullable()->index();
            $t->string('token', 120)->unique();
            $t->string('token_hash', 80)->index();
            $t->string('purpose', 30)->index()->default('CHECK_IN');
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->dateTime('issued_at')->nullable();
            $t->dateTime('expires_at')->index();
            $t->dateTime('used_at')->nullable();
            $t->integer('use_count')->default(0);
            $t->integer('max_uses')->default(1);
            $t->boolean('is_revoked')->index()->default(false);
            $t->dateTime('revoked_at')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registration_tokens');
        Schema::dropIfExists('event_badges');
        Schema::dropIfExists('event_consents');
        Schema::dropIfExists('event_waitlists');
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('event_registration_answers');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('event_registration_fields');
    }
};
