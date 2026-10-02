<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_round_tables.
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
        Schema::create('competition_rounds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->foreignId('category_id')->index()->constrained('competition_categories')->cascadeOnDelete();
            $t->string('code', 60)->index();
            $t->string('name', 200);
            $t->integer('sequence')->index()->default(1);
            $t->string('round_type', 40)->index()->default('QUALIFICATION');
            $t->string('format', 40)->index()->default('SINGLE');
            $t->string('scoring_method', 40)->nullable();
            $t->string('advancement_rule', 40)->nullable();
            $t->integer('qualification_count')->nullable();
            $t->decimal('qualification_threshold')->nullable();
            $t->integer('bracket_size')->nullable();
            $t->dateTime('starts_on')->nullable()->index();
            $t->dateTime('ends_on')->nullable();
            $t->boolean('is_final')->index()->default(false);
            $t->string('status', 30)->index()->default('DRAFT');
            $t->dateTime('published_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable()->index();
            $t->json('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_groups', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('round_id')->index()->constrained('competition_rounds')->cascadeOnDelete();
            $t->string('code', 40)->index();
            $t->string('name', 120);
            $t->integer('capacity')->nullable();
            $t->integer('qualification_count')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->integer('sort_order')->default(0);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_group_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('group_id')->index()->constrained('competition_groups')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->integer('seed')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->decimal('points')->nullable();
            $t->integer('played')->default(0);
            $t->integer('won')->default(0);
            $t->integer('drawn')->default(0);
            $t->integer('lost')->default(0);
            $t->decimal('score_for')->nullable();
            $t->decimal('score_against')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_brackets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('round_id')->index()->constrained('competition_rounds')->cascadeOnDelete();
            $t->string('bracket_type', 30)->index()->default('KNOCKOUT');
            $t->integer('size')->default(0);
            $t->string('status', 20)->index()->default('DRAFT');
            $t->unsignedBigInteger('generated_by')->nullable();
            $t->dateTime('generated_at')->nullable();
            $t->boolean('is_locked')->default(false);
            $t->json('render_data')->nullable();
            $t->json('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_bracket_slots', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('bracket_id')->index()->constrained('competition_brackets')->cascadeOnDelete();
            $t->integer('round_number')->index()->default(1);
            $t->integer('position')->index()->default(1);
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('source_slot_id')->nullable();
            $t->unsignedBigInteger('winner_slot_id')->nullable();
            $t->integer('seed')->nullable();
            $t->string('status', 20)->index()->default('EMPTY');
            $t->boolean('is_bye')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_advancements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('round_id')->index()->constrained('competition_rounds')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('from_round_id')->nullable()->index();
            $t->unsignedBigInteger('to_round_id')->nullable()->index();
            $t->string('status', 30)->index()->default('ADVANCED');
            $t->integer('rank_at_advancement')->nullable();
            $t->decimal('score_at_advancement')->nullable();
            $t->string('method', 30)->index()->default('RANKING');
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_advancements');
        Schema::dropIfExists('competition_bracket_slots');
        Schema::dropIfExists('competition_brackets');
        Schema::dropIfExists('competition_group_members');
        Schema::dropIfExists('competition_groups');
        Schema::dropIfExists('competition_rounds');
    }
};
