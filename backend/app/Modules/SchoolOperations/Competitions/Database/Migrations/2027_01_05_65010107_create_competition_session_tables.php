<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_session_tables.
 *
 * Catatan integrasi (§110): modul ini hanya menyimpan keadaan khas kompetisi —
 * pendaftaran, pertandingan, nilai, hasil, penghargaan. Data siswa, pegawai,
 * keuangan, fasilitas, dan pencapaian tetap milik modul asalnya dan hanya
 * dirujuk lewat kolom `_id` tanpa kunci asing keras.
 *
 * Kunci asing keras (cascade) hanya dipakai pada tabel yang benar-benar terikat
 * siklus hidup induknya, mis. criterion milik rubric.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_sessions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('round_id')->nullable()->index();
            $t->unsignedBigInteger('event_session_id')->nullable()->index();
            $t->string('code', 60)->index();
            $t->string('name', 200);
            $t->string('session_type', 40)->index()->default('JUDGING');
            $t->dateTime('starts_at')->index();
            $t->dateTime('ends_at')->nullable();
            $t->string('venue_name', 150)->nullable();
            $t->unsignedBigInteger('room_id')->nullable()->index();
            $t->string('online_url', 255)->nullable();
            $t->integer('capacity')->nullable();
            $t->integer('judge_panel_size')->nullable();
            $t->string('status', 30)->index()->default('SCHEDULED');
            $t->dateTime('started_at')->nullable();
            $t->dateTime('ended_at')->nullable();
            $t->text('notes')->nullable();
            $t->json('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_matches', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('round_id')->index()->constrained('competition_rounds')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('group_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->integer('match_number')->index();
            $t->string('code', 60)->nullable()->index();
            $t->string('name', 200)->nullable();
            $t->unsignedBigInteger('home_participant_id')->nullable()->index();
            $t->unsignedBigInteger('home_team_id')->nullable()->index();
            $t->unsignedBigInteger('away_participant_id')->nullable()->index();
            $t->unsignedBigInteger('away_team_id')->nullable()->index();
            $t->string('venue_name', 150)->nullable();
            $t->unsignedBigInteger('room_id')->nullable()->index();
            $t->dateTime('scheduled_at')->nullable()->index();
            $t->dateTime('scheduled_end_at')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('ended_at')->nullable();
            $t->integer('duration_minutes')->nullable();
            $t->string('status', 30)->index()->default('SCHEDULED');
            $t->decimal('score_a')->nullable();
            $t->decimal('score_b')->nullable();
            $t->boolean('is_draw')->default(false);
            $t->unsignedBigInteger('winner_participant_id')->nullable()->index();
            $t->unsignedBigInteger('winner_team_id')->nullable()->index();
            $t->text('result_summary')->nullable();
            $t->json('officials')->nullable();
            $t->text('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_checkins', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->unsignedBigInteger('match_id')->nullable()->index();
            $t->string('checkin_method', 30)->index()->default('MANUAL');
            $t->string('identity_verification_method', 30)->nullable()->index();
            $t->string('identity_reference', 120)->nullable();
            $t->string('status', 30)->index()->default('CHECKED_IN');
            $t->boolean('eligibility_confirmed')->default(false);
            $t->dateTime('checked_in_at')->index();
            $t->unsignedBigInteger('checked_in_by')->nullable();
            $t->string('device_id', 120)->nullable();
            $t->string('location', 150)->nullable();
            $t->dateTime('offline_created_at')->nullable();
            $t->string('idempotency_key', 120)->nullable()->index();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_qr_tokens', function (Blueprint $t): void {
            $t->id();
            $t->string('token', 120)->unique();
            $t->string('purpose', 40)->index()->default('CHECKIN');
            $t->string('tokenable_type', 150)->nullable();
            $t->unsignedBigInteger('tokenable_id')->nullable()->index();
            $t->unsignedBigInteger('edition_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('match_id')->nullable()->index();
            $t->json('payload')->nullable();
            $t->dateTime('expires_at')->index();
            $t->integer('max_uses')->default(1);
            $t->integer('used_count')->default(0);
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->dateTime('issued_at')->nullable();
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->dateTime('revoked_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('technical_meetings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->string('title', 200);
            $t->text('agenda')->nullable();
            $t->dateTime('starts_at')->index();
            $t->dateTime('ends_at')->nullable();
            $t->string('mode', 20)->index()->default('OFFLINE');
            $t->string('venue_name', 150)->nullable();
            $t->unsignedBigInteger('room_id')->nullable()->index();
            $t->string('online_url', 255)->nullable();
            $t->string('status', 30)->index()->default('SCHEDULED');
            $t->text('minutes')->nullable();
            $t->string('recording_url', 255)->nullable();
            $t->json('documents')->nullable();
            $t->boolean('draw_completed')->default(false);
            $t->dateTime('draw_completed_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('technical_meeting_attendances', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('meeting_id')->index()->constrained('technical_meetings')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->string('attendee_name', 200)->nullable();
            $t->string('attendee_role', 40)->nullable();
            $t->string('status', 20)->index()->default('INVITED');
            $t->dateTime('attended_at')->nullable();
            $t->text('signature_data')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_meeting_attendances');
        Schema::dropIfExists('technical_meetings');
        Schema::dropIfExists('competition_qr_tokens');
        Schema::dropIfExists('competition_checkins');
        Schema::dropIfExists('competition_matches');
        Schema::dropIfExists('competition_sessions');
    }
};
