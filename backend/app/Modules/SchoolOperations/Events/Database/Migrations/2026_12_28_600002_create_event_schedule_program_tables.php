<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Event (School Operations Fase 4) — 600002_create_event_schedule_program_tables.
 *
 * Catatan: kolom `_id` diberi indeks; kunci asing keras hanya dipakai pada
 * tabel anak yang memang terikat siklus hidup induknya (cascade), sehingga
 * pengujian lintas modul tidak bergantung urutan penghapusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_schedules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->date('schedule_date')->index();
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->boolean('is_full_day')->default(false);
            $t->string('title', 200)->nullable();
            $t->string('location', 255)->nullable();
            $t->unsignedBigInteger('venue_id')->nullable()->index();
            $t->string('timezone', 60)->nullable();
            $t->integer('sequence')->default(1);
            $t->unsignedBigInteger('calendar_event_id')->nullable();
            $t->json('calendar_targets')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_sessions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('program_id')->nullable()->index();
            $t->unsignedBigInteger('schedule_id')->nullable()->index();
            $t->string('code', 50)->nullable();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->string('session_type', 30)->default('SESSION');
            $t->date('session_date')->nullable()->index();
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->unsignedBigInteger('venue_id')->nullable()->index();
            $t->string('room', 100)->nullable();
            $t->integer('capacity')->nullable();
            $t->integer('registered_count')->default(0);
            $t->integer('attended_count')->default(0);
            $t->string('participant_group', 100)->nullable();
            $t->unsignedBigInteger('speaker_id')->nullable()->index();
            $t->boolean('requires_attendance')->default(true);
            $t->boolean('is_mandatory')->default(true);
            $t->integer('sequence')->default(1);
            $t->string('status', 20)->index()->default('SCHEDULED');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_programs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->date('program_date')->nullable()->index();
            $t->integer('version')->default(1);
            $t->boolean('is_active')->index()->default(true);
            $t->string('status', 20)->index()->default('DRAFT');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_program_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('program_id')->index()->constrained('event_programs')->cascadeOnDelete();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->integer('sequence')->index()->default(1);
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->integer('duration_minutes')->nullable();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('item_type', 30)->default('AGENDA');
            $t->unsignedBigInteger('speaker_id')->nullable()->index();
            $t->string('speaker_name', 150)->nullable();
            $t->string('responsible_person', 150)->nullable();
            $t->unsignedBigInteger('responsible_user_id')->nullable()->index();
            $t->string('location', 255)->nullable();
            $t->unsignedBigInteger('venue_id')->nullable()->index();
            $t->string('status', 20)->index()->default('PLANNED');
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->integer('delay_minutes')->nullable();
            $t->text('note')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_program_dependencies', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('program_item_id')->index()->constrained('event_program_items')->cascadeOnDelete();
            $t->unsignedBigInteger('depends_on_item_id')->index();
            $t->string('dependency_type', 20)->default('FINISH_TO_START');
            $t->boolean('is_blocking')->default(true);
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_speakers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->string('speaker_type', 30)->index()->default('EXTERNAL');
            $t->string('source_type', 30)->nullable();
            $t->unsignedBigInteger('source_id')->nullable()->index();
            $t->string('name', 150);
            $t->string('title', 100)->nullable();
            $t->string('organization', 200)->nullable();
            $t->string('position', 150)->nullable();
            $t->text('bio')->nullable();
            $t->string('email', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->unsignedBigInteger('photo_document_id')->nullable();
            $t->string('topic', 255)->nullable();
            $t->text('requirements')->nullable();
            $t->decimal('honorarium')->nullable();
            $t->boolean('accommodation_required')->default(false);
            $t->boolean('transportation_required')->default(false);
            $t->string('invitation_status', 30)->index()->default('INVITED');
            $t->dateTime('confirmed_at')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_guests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('invitation_id')->nullable()->index();
            $t->string('guest_type', 30)->index()->default('EXTERNAL');
            $t->string('name', 150);
            $t->string('organization', 200)->nullable();
            $t->string('role', 100)->nullable();
            $t->string('position', 150)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('access_level', 30)->index()->default('GENERAL');
            $t->string('invitation_status', 30)->index()->default('INVITED');
            $t->dateTime('arrival_at')->nullable();
            $t->dateTime('departure_at')->nullable();
            $t->boolean('is_vip')->index()->default(false);
            $t->string('seat_number', 30)->nullable();
            $t->string('vehicle_plate', 30)->nullable();
            $t->boolean('needs_accommodation')->default(false);
            $t->boolean('needs_transportation')->default(false);
            $t->string('dietary_requirement', 150)->nullable();
            $t->integer('companion_count')->default(0);
            $t->unsignedBigInteger('badge_id')->nullable();
            $t->string('qr_token', 80)->nullable()->unique();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_invitations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('guest_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('speaker_id')->nullable()->index();
            $t->string('invitation_type', 30)->default('GUEST');
            $t->string('channel', 30)->index()->default('EMAIL');
            $t->string('recipient_name', 150)->nullable();
            $t->string('recipient_address', 200);
            $t->string('subject', 255)->nullable();
            $t->text('message')->nullable();
            $t->string('template_code', 60)->nullable();
            $t->unsignedBigInteger('attachment_document_id')->nullable();
            $t->string('status', 30)->index()->default('DRAFT');
            $t->dateTime('sent_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->dateTime('opened_at')->nullable();
            $t->dateTime('confirmed_at')->nullable();
            $t->dateTime('declined_at')->nullable();
            $t->text('decline_reason')->nullable();
            $t->integer('reminder_count')->default(0);
            $t->dateTime('last_reminder_at')->nullable();
            $t->unsignedBigInteger('notification_id')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_invitations');
        Schema::dropIfExists('event_guests');
        Schema::dropIfExists('event_speakers');
        Schema::dropIfExists('event_program_dependencies');
        Schema::dropIfExists('event_program_items');
        Schema::dropIfExists('event_programs');
        Schema::dropIfExists('event_sessions');
        Schema::dropIfExists('event_schedules');
    }
};
