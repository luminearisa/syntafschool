<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_participant_tables.
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
        Schema::create('competition_teams', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->foreignId('category_id')->index()->constrained('competition_categories')->cascadeOnDelete();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->string('code', 60)->index();
            $t->string('name', 200);
            $t->string('institution_name', 200)->nullable();
            $t->unsignedBigInteger('campus_id')->nullable()->index();
            $t->unsignedBigInteger('coach_employee_id')->nullable()->index();
            $t->unsignedBigInteger('mentor_employee_id')->nullable()->index();
            $t->unsignedBigInteger('captain_participant_id')->nullable()->index();
            $t->string('manager_name', 200)->nullable();
            $t->string('manager_contact', 60)->nullable();
            $t->string('jersey_color', 40)->nullable();
            $t->string('home_venue', 150)->nullable();
            $t->string('status', 30)->index()->default('DRAFT');
            $t->boolean('roster_locked')->index()->default(false);
            $t->dateTime('roster_locked_at')->nullable();
            $t->unsignedBigInteger('roster_locked_by')->nullable();
            $t->integer('seed_number')->nullable();
            $t->text('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_participants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->foreignId('category_id')->index()->constrained('competition_categories')->cascadeOnDelete();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->string('participant_type', 30)->index()->default('STUDENT');
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->string('display_name', 200)->nullable();
            $t->string('institution_name', 200)->nullable();
            $t->unsignedBigInteger('campus_id')->nullable()->index();
            $t->string('team_role', 40)->nullable();
            $t->boolean('is_captain')->default(false);
            $t->boolean('is_substitute')->default(false);
            $t->string('jersey_number', 20)->nullable();
            $t->integer('seed_number')->nullable();
            $t->string('bib_number', 30)->nullable();
            $t->string('status', 30)->index()->default('ACTIVE');
            $t->string('qualification_status', 30)->nullable()->index();
            $t->string('eligibility_status', 30)->nullable()->index();
            $t->boolean('is_active')->index()->default(true);
            $t->dateTime('withdrawn_at')->nullable();
            $t->text('withdrawal_reason')->nullable();
            $t->text('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_team_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('team_id')->index()->constrained('competition_teams')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->string('member_role', 40)->index()->default('MEMBER');
            $t->string('position', 60)->nullable();
            $t->string('jersey_number', 20)->nullable();
            $t->boolean('is_captain')->default(false);
            $t->boolean('is_substitute')->default(false);
            $t->string('eligibility_status', 30)->nullable()->index();
            $t->dateTime('eligibility_checked_at')->nullable();
            $t->dateTime('joined_at')->nullable();
            $t->dateTime('left_at')->nullable();
            $t->unsignedBigInteger('replaced_by_member_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_selections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('category_id')->index()->constrained('competition_categories')->cascadeOnDelete();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->string('selection_method', 30)->index()->default('MANUAL');
            $t->string('status', 30)->index()->default('PENDING');
            $t->decimal('score')->nullable();
            $t->integer('rank')->nullable();
            $t->integer('quota_position')->nullable();
            $t->unsignedBigInteger('selected_by')->nullable();
            $t->dateTime('selected_at')->nullable();
            $t->json('evidence')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_waitlists', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('category_id')->index()->constrained('competition_categories')->cascadeOnDelete();
            $t->foreignId('registration_id')->index()->constrained('competition_registrations')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->integer('position')->index();
            $t->integer('priority')->index()->default(0);
            $t->string('status', 30)->index()->default('WAITING');
            $t->dateTime('offered_at')->nullable();
            $t->dateTime('offer_expires_at')->nullable();
            $t->dateTime('confirmed_at')->nullable();
            $t->dateTime('promoted_at')->nullable();
            $t->unsignedBigInteger('promoted_by')->nullable();
            $t->text('notes')->nullable();
            $t->string('idempotency_key', 120)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_waitlists');
        Schema::dropIfExists('competition_selections');
        Schema::dropIfExists('competition_team_members');
        Schema::dropIfExists('competition_participants');
        Schema::dropIfExists('competition_teams');
    }
};
