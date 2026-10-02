<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_appeal_tables.
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
        Schema::create('competition_protests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('round_id')->nullable()->index();
            $t->unsignedBigInteger('match_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('submitted_by')->nullable()->index();
            $t->dateTime('submitted_at')->nullable();
            $t->string('protest_type', 40)->index()->default('SCORE_REVIEW');
            $t->string('subject', 200);
            $t->text('description');
            $t->json('evidence')->nullable();
            $t->string('status', 30)->index()->default('SUBMITTED');
            $t->dateTime('acknowledged_at')->nullable();
            $t->unsignedBigInteger('acknowledged_by')->nullable();
            $t->dateTime('resolved_at')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->string('decision', 30)->nullable();
            $t->text('resolution')->nullable();
            $t->dateTime('appeal_deadline')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_appeal_committees', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->string('name', 200);
            $t->unsignedBigInteger('chair_employee_id')->nullable()->index();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->dateTime('constituted_at')->nullable();
            $t->dateTime('dissolved_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_appeal_committee_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('committee_id')->index()->constrained('competition_appeal_committees')->cascadeOnDelete();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('member_name', 200)->nullable();
            $t->string('member_role', 30)->index()->default('MEMBER');
            $t->boolean('is_voting')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_appeals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('protest_id')->nullable()->index();
            $t->unsignedBigInteger('result_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('submitted_by')->nullable()->index();
            $t->string('appeal_type', 40)->index()->default('SCORE_REVIEW');
            $t->text('grounds');
            $t->text('description')->nullable();
            $t->json('evidence')->nullable();
            $t->text('requested_remedy')->nullable();
            $t->string('status', 30)->index()->default('SUBMITTED');
            $t->dateTime('submitted_at')->nullable();
            $t->dateTime('deadline_at')->nullable();
            $t->boolean('is_late')->default(false);
            $t->unsignedBigInteger('committee_id')->nullable()->index();
            $t->dateTime('decided_at')->nullable();
            $t->string('decision', 30)->nullable()->index();
            $t->text('decision_reason')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->boolean('is_final')->default(false);
            $t->dateTime('review_started_at')->nullable();
            $t->text('review_notes')->nullable();
            $t->integer('revision_number')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_incidents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('round_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->unsignedBigInteger('match_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->string('incident_type', 40)->index()->default('OTHER');
            $t->string('severity', 20)->index()->default('LOW');
            $t->string('title', 200);
            $t->text('description');
            $t->json('evidence')->nullable();
            $t->text('action_taken')->nullable();
            $t->string('status', 20)->index()->default('OPEN');
            $t->unsignedBigInteger('reported_by')->nullable();
            $t->dateTime('reported_at')->index();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->dateTime('resolved_at')->nullable();
            $t->text('resolution')->nullable();
            $t->string('clinic_reference_id', 120)->nullable();
            $t->unsignedBigInteger('event_incident_id')->nullable()->index();
            $t->boolean('requires_follow_up')->index()->default(false);
            $t->text('follow_up_notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_incidents');
        Schema::dropIfExists('competition_appeals');
        Schema::dropIfExists('competition_appeal_committee_members');
        Schema::dropIfExists('competition_appeal_committees');
        Schema::dropIfExists('competition_protests');
    }
};
