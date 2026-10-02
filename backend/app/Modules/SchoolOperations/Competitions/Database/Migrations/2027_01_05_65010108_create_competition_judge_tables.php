<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_judge_tables.
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
        Schema::create('competition_judges', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('campus_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('display_name', 200);
            $t->string('expertise', 200)->nullable();
            $t->string('qualification', 200)->nullable();
            $t->string('organization_name', 200)->nullable();
            $t->string('certification_number', 100)->nullable();
            $t->date('certification_expires_on')->nullable();
            $t->boolean('is_certified')->index()->default(false);
            $t->integer('max_concurrent_assignments')->nullable();
            $t->text('availability_notes')->nullable();
            $t->json('conflict_declaration')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->boolean('is_active')->index()->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_judge_assignments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->foreignId('judge_id')->index()->constrained('competition_judges')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('round_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->unsignedBigInteger('match_id')->nullable()->index();
            $t->string('assignment_role', 30)->index()->default('JUDGE');
            $t->boolean('is_head_judge')->index()->default(false);
            $t->string('status', 20)->index()->default('ASSIGNED');
            $t->unsignedBigInteger('assigned_by')->nullable();
            $t->dateTime('assigned_at')->nullable();
            $t->dateTime('accepted_at')->nullable();
            $t->dateTime('declined_at')->nullable();
            $t->text('decline_reason')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_judge_conflicts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('judge_id')->index()->constrained('competition_judges')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->string('institution_name', 200)->nullable();
            $t->string('conflict_type', 40)->index()->default('PERSONAL');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('declared_by')->nullable();
            $t->dateTime('declared_at')->nullable();
            $t->string('status', 20)->index()->default('DECLARED');
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->string('resolution', 30)->nullable();
            $t->text('resolution_note')->nullable();
            $t->unsignedBigInteger('assigned_substitute_judge_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_judge_conflicts');
        Schema::dropIfExists('competition_judge_assignments');
        Schema::dropIfExists('competition_judges');
    }
};
