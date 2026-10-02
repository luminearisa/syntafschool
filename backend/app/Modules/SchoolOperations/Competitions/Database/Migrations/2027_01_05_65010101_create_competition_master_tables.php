<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_master_tables.
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
        Schema::create('competition_domains', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 100);
            $t->text('description')->nullable();
            $t->string('color', 20)->nullable();
            $t->string('icon', 40)->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->index()->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_types', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 120);
            $t->unsignedBigInteger('competition_domain_id')->nullable()->index();
            $t->text('description')->nullable();
            $t->string('default_format', 40)->index()->default('JUDGED');
            $t->string('default_scoring_model', 40)->index()->default('WEIGHTED');
            $t->string('default_ranking_model', 40)->index()->default('HIGHER_BETTER');
            $t->string('participant_mode', 20)->index()->default('INDIVIDUAL');
            $t->boolean('requires_team')->default(false);
            $t->boolean('requires_judge')->default(true);
            $t->boolean('requires_submission')->default(false);
            $t->boolean('allows_multiple_rounds')->default(true);
            $t->boolean('supports_bracket')->default(false);
            $t->boolean('supports_heat')->default(false);
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->index()->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_formats', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 100);
            $t->text('description')->nullable();
            $t->string('family', 40)->index()->default('SINGLE');
            $t->boolean('supports_advancement')->default(false);
            $t->boolean('requires_bracket')->default(false);
            $t->boolean('requires_pairing')->default(false);
            $t->integer('min_participants')->nullable();
            $t->integer('max_participants')->nullable();
            $t->json('settings_schema')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->index()->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_levels', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 100);
            $t->string('scope', 20)->index()->default('INTERNAL');
            $t->integer('rank')->nullable();
            $t->text('description')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->index()->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_divisions', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 100);
            $t->string('division_type', 30)->index()->default('GRADE');
            $t->integer('min_age')->nullable();
            $t->integer('max_age')->nullable();
            $t->string('min_grade_level', 20)->nullable();
            $t->string('max_grade_level', 20)->nullable();
            $t->string('gender', 20)->index()->default('ANY');
            $t->string('skill_level', 30)->nullable();
            $t->text('description')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->index()->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_divisions');
        Schema::dropIfExists('competition_levels');
        Schema::dropIfExists('competition_formats');
        Schema::dropIfExists('competition_types');
        Schema::dropIfExists('competition_domains');
    }
};
