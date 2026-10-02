<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_rules_tables.
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
        Schema::create('competition_rules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->string('code', 60)->index();
            $t->string('name', 200);
            $t->string('rules_type', 40)->index()->default('CONDUCT');
            $t->text('description')->nullable();
            $t->boolean('is_mandatory')->default(true);
            $t->integer('sort_order')->default(0);
            $t->string('status', 20)->index()->default('DRAFT');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_rule_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('rule_id')->index()->constrained('competition_rules')->cascadeOnDelete();
            $t->integer('version')->default(1);
            $t->text('content')->nullable();
            $t->json('structured_content')->nullable();
            $t->text('change_summary')->nullable();
            $t->string('status', 20)->index()->default('DRAFT');
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->dateTime('published_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable()->index();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_rubrics', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('round_id')->nullable()->index();
            $t->string('code', 60)->index();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->integer('version')->default(1);
            $t->decimal('total_weight')->default(100);
            $t->string('scoring_type', 40)->index()->default('WEIGHTED');
            $t->string('application_scope', 30)->default('CATEGORY');
            $t->boolean('is_default')->index()->default(false);
            $t->boolean('is_active')->index()->default(true);
            $t->string('status', 20)->index()->default('DRAFT');
            $t->dateTime('published_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable()->index();
            $t->json('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_rubric_criteria', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('rubric_id')->index()->constrained('competition_rubrics')->cascadeOnDelete();
            $t->string('code', 60)->index();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->decimal('weight')->default(0);
            $t->decimal('min_score')->default(0);
            $t->decimal('max_score')->default(100);
            $t->decimal('step')->nullable();
            $t->string('scoring_type', 40)->index()->default('NUMERIC');
            $t->boolean('is_required')->default(true);
            $t->boolean('required_comment')->default(false);
            $t->boolean('penalty_enabled')->default(false);
            $t->decimal('max_penalty')->nullable();
            $t->integer('sort_order')->default(0);
            $t->string('help_text', 255)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_rubric_criteria');
        Schema::dropIfExists('competition_rubrics');
        Schema::dropIfExists('competition_rule_versions');
        Schema::dropIfExists('competition_rules');
    }
};
