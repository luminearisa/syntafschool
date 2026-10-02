<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_core_tables.
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
        Schema::create('competitions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organization_id')->nullable()->index();
            $t->unsignedBigInteger('campus_id')->nullable()->index();
            $t->foreignId('competition_type_id')->index()->constrained('competition_types')->cascadeOnDelete();
            $t->unsignedBigInteger('competition_domain_id')->nullable()->index();
            $t->unsignedBigInteger('template_id')->nullable()->index();
            $t->string('code', 60)->unique();
            $t->string('name', 200);
            $t->string('slug', 220)->unique();
            $t->string('short_name', 80)->nullable();
            $t->text('description')->nullable();
            $t->string('organizer_name', 200)->nullable();
            $t->string('organizer_contact_email', 180)->nullable();
            $t->string('organizer_contact_phone', 40)->nullable();
            $t->unsignedBigInteger('organizer_organization_id')->nullable()->index();
            $t->boolean('is_internal')->index()->default(true);
            $t->boolean('is_recurring')->default(false);
            $t->string('recurrence_pattern', 40)->nullable();
            $t->string('default_visibility', 20)->index()->default('INTERNAL');
            $t->string('default_participant_mode', 20)->index()->default('INDIVIDUAL');
            $t->string('status', 30)->index()->default('DRAFT');
            $t->unsignedBigInteger('created_by')->nullable()->index();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->text('notes')->nullable();
            $t->json('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_editions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('competition_id')->index()->constrained('competitions')->cascadeOnDelete();
            $t->unsignedBigInteger('level_id')->nullable()->index();
            $t->unsignedBigInteger('campus_id')->nullable()->index();
            $t->unsignedBigInteger('organization_id')->nullable()->index();
            $t->integer('year')->index();
            $t->string('code', 60)->unique();
            $t->string('name', 200);
            $t->string('slug', 220)->unique();
            $t->string('theme', 200)->nullable();
            $t->string('organizer_name', 200)->nullable();
            $t->string('host_name', 200)->nullable();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->text('description')->nullable();
            $t->text('terms')->nullable();
            $t->date('starts_on')->nullable()->index();
            $t->date('ends_on')->nullable()->index();
            $t->dateTime('registration_opens')->nullable()->index();
            $t->dateTime('registration_closes')->nullable()->index();
            $t->dateTime('technical_meeting_at')->nullable();
            $t->string('status', 30)->index()->default('DRAFT');
            $t->string('participant_type', 30)->nullable()->index();
            $t->string('registration_mode', 30)->index()->default('OPEN');
            $t->integer('max_participants')->nullable();
            $t->integer('max_teams')->nullable();
            $t->boolean('allows_external')->default(false);
            $t->boolean('allows_internal')->default(true);
            $t->boolean('requires_parent_consent')->default(false);
            $t->boolean('requires_payment')->default(false);
            $t->boolean('requires_eligibility')->default(true);
            $t->boolean('requires_selection')->default(false);
            $t->boolean('requires_documents')->default(false);
            $t->string('timezone', 60)->default('Asia/Jakarta');
            $t->string('public_visibility', 20)->index()->default('INTERNAL');
            $t->dateTime('published_at')->nullable();
            $t->dateTime('locked_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable()->index();
            $t->json('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_edition_divisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->foreignId('division_id')->index()->constrained('competition_divisions')->cascadeOnDelete();
            $t->integer('capacity')->nullable();
            $t->boolean('is_active')->index()->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_categories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('competition_division_id')->nullable()->index();
            $t->string('code', 60)->index();
            $t->string('name', 200);
            $t->string('slug', 220)->nullable()->index();
            $t->text('description')->nullable();
            $t->string('gender_restriction', 20)->index()->default('ANY');
            $t->string('min_grade_level', 20)->nullable();
            $t->string('max_grade_level', 20)->nullable();
            $t->string('program_restriction', 120)->nullable();
            $t->integer('min_age')->nullable();
            $t->integer('max_age')->nullable();
            $t->string('participant_mode', 20)->index()->default('INDIVIDUAL');
            $t->integer('min_team_size')->nullable();
            $t->integer('max_team_size')->nullable();
            $t->integer('capacity')->nullable();
            $t->integer('min_participants')->nullable();
            $t->decimal('registration_fee')->nullable();
            $t->string('currency', 10)->default('IDR');
            $t->string('fee_mode', 20)->index()->default('PER_PARTICIPANT');
            $t->string('qualification_requirement', 30)->nullable();
            $t->string('scoring_model', 40)->index()->default('WEIGHTED');
            $t->string('ranking_model', 40)->index()->default('HIGHER_BETTER');
            $t->string('aggregation_method', 30)->index()->default('AVERAGE');
            $t->json('tie_break_strategy')->nullable();
            $t->json('award_configuration')->nullable();
            $t->string('round_format', 40)->nullable();
            $t->string('selection_method', 30)->nullable();
            $t->boolean('requires_submission')->default(false);
            $t->boolean('requires_judge')->default(true);
            $t->boolean('requires_documents')->default(false);
            $t->boolean('requires_parent_consent')->default(false);
            $t->boolean('is_active')->index()->default(true);
            $t->integer('sort_order')->default(0);
            $t->json('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_category_eligibility_rules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('category_id')->index()->constrained('competition_categories')->cascadeOnDelete();
            $t->string('rule_code', 60)->index();
            $t->string('name', 150);
            $t->string('rule_type', 40)->index();
            $t->string('operator', 20)->index()->default('GTE');
            $t->string('expected_value', 255)->nullable();
            $t->string('value_type', 20)->default('STRING');
            $t->json('parameters')->nullable();
            $t->boolean('is_required')->default(true);
            $t->decimal('weight')->nullable();
            $t->string('failure_message', 255)->nullable();
            $t->integer('sort_order')->default(0);
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_category_eligibility_rules');
        Schema::dropIfExists('competition_categories');
        Schema::dropIfExists('competition_edition_divisions');
        Schema::dropIfExists('competition_editions');
        Schema::dropIfExists('competitions');
    }
};
