<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_scoring_tables.
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
        Schema::create('competition_scorecards', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('rubric_id')->index()->constrained('competition_rubrics')->cascadeOnDelete();
            $t->unsignedBigInteger('judge_id')->nullable()->index();
            $t->unsignedBigInteger('edition_id')->nullable()->index();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('round_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->unsignedBigInteger('match_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->string('status', 20)->index()->default('DRAFT');
            $t->integer('current_version')->default(1);
            $t->decimal('total_raw_score')->nullable();
            $t->decimal('weighted_score')->nullable();
            $t->decimal('penalty_total')->default(0);
            $t->decimal('final_score')->nullable();
            $t->decimal('normalized_score')->nullable();
            $t->text('comment')->nullable();
            $t->boolean('is_head_judge_override')->default(false);
            $t->dateTime('submitted_at')->nullable();
            $t->dateTime('locked_at')->nullable();
            $t->unsignedBigInteger('locked_by')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->string('device_id', 120)->nullable();
            $t->dateTime('offline_created_at')->nullable();
            $t->string('idempotency_key', 120)->nullable()->index();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_scores', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('scorecard_id')->index()->constrained('competition_scorecards')->cascadeOnDelete();
            $t->foreignId('criterion_id')->index()->constrained('competition_rubric_criteria')->cascadeOnDelete();
            $t->decimal('raw_score')->nullable();
            $t->decimal('weighted_score')->nullable();
            $t->text('comment')->nullable();
            $t->boolean('is_penalty')->default(false);
            $t->decimal('penalty_value')->nullable();
            $t->integer('sort_order')->default(0);
            $t->dateTime('entered_at')->nullable();
            $t->unsignedBigInteger('entered_by')->nullable();
            $t->string('idempotency_key', 120)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_score_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('scorecard_id')->index()->constrained('competition_scorecards')->cascadeOnDelete();
            $t->integer('version')->default(1);
            $t->decimal('previous_total')->nullable();
            $t->decimal('new_total')->nullable();
            $t->json('previous_payload')->nullable();
            $t->json('new_payload')->nullable();
            $t->text('reason')->nullable();
            $t->unsignedBigInteger('amendment_request_id')->nullable()->index();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 150)->nullable();
            $t->unsignedBigInteger('approver_id')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->softDeletes();
        });
        Schema::create('competition_score_amendments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('scorecard_id')->index()->constrained('competition_scorecards')->cascadeOnDelete();
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->dateTime('requested_at')->nullable();
            $t->text('reason');
            $t->json('proposed_payload')->nullable();
            $t->string('status', 20)->index()->default('PENDING');
            $t->unsignedBigInteger('authorized_by')->nullable();
            $t->dateTime('authorized_at')->nullable();
            $t->text('rejected_reason')->nullable();
            $t->dateTime('applied_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_penalties', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('round_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->unsignedBigInteger('match_id')->nullable()->index();
            $t->unsignedBigInteger('scorecard_id')->nullable()->index();
            $t->string('penalty_type', 40)->index()->default('RULE_VIOLATION');
            $t->string('rule_code', 60)->nullable();
            $t->string('value_type', 20)->index()->default('POINTS');
            $t->decimal('penalty_value')->default(0);
            $t->text('reason');
            $t->json('evidence')->nullable();
            $t->string('status', 20)->index()->default('APPLIED');
            $t->unsignedBigInteger('applied_by')->nullable();
            $t->dateTime('applied_at')->nullable();
            $t->unsignedBigInteger('authorized_by')->nullable();
            $t->unsignedBigInteger('reversed_by')->nullable();
            $t->dateTime('reversed_at')->nullable();
            $t->text('reversal_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_tie_breaks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('round_id')->index()->constrained('competition_rounds')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->string('strategy', 40)->index()->default('HEAD_TO_HEAD');
            $t->json('tied_participant_ids')->nullable();
            $t->json('resolved_order')->nullable();
            $t->boolean('unresolved')->default(false);
            $t->dateTime('resolved_at')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->json('result_snapshot')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_submissions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('category_id')->index()->constrained('competition_categories')->cascadeOnDelete();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->string('title', 255);
            $t->text('description')->nullable();
            $t->string('external_url', 255)->nullable();
            $t->string('status', 30)->index()->default('DRAFT');
            $t->integer('current_version')->default(1);
            $t->dateTime('deadline_at')->nullable()->index();
            $t->dateTime('submitted_at')->nullable();
            $t->boolean('is_late')->index()->default(false);
            $t->integer('grace_period_minutes')->nullable();
            $t->decimal('late_penalty_applied')->nullable();
            $t->dateTime('locked_at')->nullable();
            $t->unsignedBigInteger('reviewer_id')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('review_notes')->nullable();
            $t->string('plagiarism_status', 20)->nullable()->index();
            $t->decimal('plagiarism_score')->nullable();
            $t->dateTime('plagiarism_checked_at')->nullable();
            $t->string('plagiarism_reference', 120)->nullable();
            $t->text('disqualified_reason')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_submission_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('submission_id')->index()->constrained('competition_submissions')->cascadeOnDelete();
            $t->integer('version')->default(1);
            $t->string('file_path', 255)->nullable();
            $t->string('file_name', 200)->nullable();
            $t->integer('file_size')->nullable();
            $t->string('mime_type', 120)->nullable();
            $t->string('checksum', 64)->nullable()->index();
            $t->text('notes')->nullable();
            $t->boolean('is_current')->index()->default(true);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->dateTime('uploaded_at')->nullable();
            $t->string('virus_scan_status', 20)->index()->default('PENDING');
            $t->dateTime('virus_scanned_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_submission_versions');
        Schema::dropIfExists('competition_submissions');
        Schema::dropIfExists('competition_tie_breaks');
        Schema::dropIfExists('competition_penalties');
        Schema::dropIfExists('competition_score_amendments');
        Schema::dropIfExists('competition_score_versions');
        Schema::dropIfExists('competition_scores');
        Schema::dropIfExists('competition_scorecards');
    }
};
