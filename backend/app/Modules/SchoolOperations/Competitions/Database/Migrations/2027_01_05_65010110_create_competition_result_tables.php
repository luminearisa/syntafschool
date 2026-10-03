<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_result_tables.
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
        Schema::create('competition_results', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->foreignId('category_id')->index()->constrained('competition_categories')->cascadeOnDelete();
            $t->unsignedBigInteger('round_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('award_id')->nullable()->index();
            $t->decimal('raw_score')->nullable();
            $t->decimal('penalty_total')->default(0);
            $t->decimal('final_score')->nullable();
            $t->decimal('aggregate_score')->nullable();
            $t->integer('rank')->nullable()->index();
            $t->integer('position')->nullable()->index();
            $t->string('qualification_status', 30)->nullable()->index();
            $t->string('status', 20)->index()->default('DRAFT');
            $t->boolean('is_winner')->index()->default(false);
            $t->boolean('tie_break_applied')->default(false);
            $t->json('tie_break_detail')->nullable();
            $t->json('score_breakdown')->nullable();
            $t->text('result_summary')->nullable();
            $t->unsignedBigInteger('finalized_by')->nullable();
            $t->dateTime('finalized_at')->nullable();
            $t->dateTime('published_at')->nullable();
            $t->unsignedBigInteger('published_by')->nullable();
            $t->boolean('is_published')->index()->default(false);
            $t->integer('version')->default(1);
            $t->string('achievement_reference', 120)->nullable();
            $t->dateTime('achievement_synced_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_rankings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->foreignId('category_id')->index()->constrained('competition_categories')->cascadeOnDelete();
            $t->unsignedBigInteger('round_id')->nullable()->index();
            $t->string('scope', 30)->index()->default('CATEGORY');
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->integer('rank')->index();
            $t->integer('previous_rank')->nullable();
            $t->decimal('score')->nullable();
            $t->decimal('tie_break_score')->nullable();
            $t->boolean('is_final')->index()->default(false);
            $t->string('calculation_version', 20)->default('1.0');
            $t->json('breakdown')->nullable();
            $t->dateTime('calculated_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_disqualifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->string('reason_type', 40)->index()->default('RULE_VIOLATION');
            $t->text('reason');
            $t->text('description')->nullable();
            $t->json('evidence')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->unsignedBigInteger('authorized_by')->nullable();
            $t->dateTime('authorized_at')->nullable();
            $t->dateTime('effective_from')->nullable();
            $t->boolean('is_appealable')->default(true);
            $t->dateTime('appeal_deadline')->nullable();
            $t->unsignedBigInteger('reversed_by')->nullable();
            $t->dateTime('reversed_at')->nullable();
            $t->text('reversal_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_awards', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->string('code', 60)->index();
            $t->string('name', 200);
            $t->string('award_type', 40)->index()->default('PLACEMENT');
            $t->string('award_level', 30)->nullable();
            $t->string('criteria_type', 30)->index()->default('RANKING');
            $t->json('criteria_definition')->nullable();
            $t->integer('position_from')->nullable();
            $t->integer('position_to')->nullable();
            $t->integer('quantity')->nullable();
            $t->string('medal_type', 30)->nullable();
            $t->text('description')->nullable();
            $t->string('certificate_template_code', 60)->nullable();
            $t->string('external_provider_reference', 120)->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->index()->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_award_recipients', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('award_id')->index()->constrained('competition_awards')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('result_id')->nullable()->index();
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->boolean('is_automatic')->default(false);
            $t->unsignedBigInteger('awarded_by')->nullable();
            $t->dateTime('awarded_at')->nullable();
            $t->unsignedBigInteger('certificate_id')->nullable()->index();
            $t->string('achievement_reference', 120)->nullable();
            $t->dateTime('achievement_synced_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_certificates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('award_id')->nullable()->index();
            $t->unsignedBigInteger('result_id')->nullable()->index();
            $t->string('recipient_type', 30)->index()->default('PARTICIPANT');
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->string('certificate_number', 80)->nullable()->unique();
            $t->string('certificate_type', 40)->index()->default('PARTICIPANT');
            $t->string('holder_name', 200);
            $t->date('issued_on')->nullable()->index();
            $t->string('verification_code', 80)->nullable()->unique();
            $t->text('qr_payload')->nullable();
            $t->string('template_code', 60)->nullable();
            $t->string('file_path', 255)->nullable();
            $t->string('status', 20)->index()->default('DRAFT');
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->dateTime('issued_at')->nullable();
            $t->string('external_provider_reference', 120)->nullable();
            $t->dateTime('revoked_at')->nullable();
            $t->text('revoked_reason')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_certificates');
        Schema::dropIfExists('competition_award_recipients');
        Schema::dropIfExists('competition_awards');
        Schema::dropIfExists('competition_disqualifications');
        Schema::dropIfExists('competition_rankings');
        Schema::dropIfExists('competition_results');
    }
};
