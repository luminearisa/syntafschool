<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kompetisi: kategori lomba, peserta, tim, babak, pertandingan, juri, skor, dan hasil.
 *
 * Alur lomba dibuat eksplisit supaya hasil dapat dipertanggungjawabkan: skor dikirim
 * juri, dikunci (LOCKED), hasil dihitung, diverifikasi, baru diterbitkan. Koreksi
 * setelah penguncian hanya lewat jejak koreksi bersebab — bukan penyuntingan diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_competitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->foreignId('program_id')->nullable()->constrained('activity_programs')->nullOnDelete();
            $table->string('level', 32)->default('SCHOOL')->index(); // INTERNAL|SCHOOL|DISTRICT|CITY|PROVINCE|NATIONAL|INTERNATIONAL
            $table->string('participant_type', 16)->default('INDIVIDUAL'); // INDIVIDUAL|TEAM|MIXED
            $table->string('organizer')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->date('registration_opens')->nullable();
            $table->date('registration_closes')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->decimal('fee_amount', 15, 2)->default(0);
            $table->string('status', 24)->default('DRAFT')->index();
            $table->boolean('scores_locked')->default(false);
            $table->boolean('results_published')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('activity_competition_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('activity_competitions')->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('name');
            $table->string('gender', 16)->default('ANY');
            $table->integer('minimum_grade_level')->nullable();
            $table->integer('maximum_grade_level')->nullable();
            $table->integer('maximum_participants')->nullable();
            $table->integer('maximum_team_size')->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->timestamps();
            $table->unique(['competition_id', 'code'], 'activity_competition_categories_unique');
        });

        Schema::create('activity_competition_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('activity_competitions')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('activity_competition_categories')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('activity_competition_teams')->nullOnDelete();
            $table->string('registration_type', 16)->default('INDIVIDUAL');
            $table->string('status', 24)->default('REGISTERED')->index(); // REGISTERED|VERIFIED|REJECTED|WITHDRAWN|FINALIZED
            $table->timestamp('registered_at')->useCurrent();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('idempotency_key', 96)->nullable();
            $table->timestamps();
            $table->unique(['competition_id', 'category_id', 'student_id'], 'activity_competition_participants_unique');
            $table->unique(['idempotency_key'], 'activity_competition_participants_idempotency_unique');
        });

        Schema::create('activity_competition_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('activity_competitions')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('activity_competition_categories')->nullOnDelete();
            $table->string('name');
            $table->foreignId('captain_student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('coach_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['competition_id', 'name'], 'activity_competition_teams_unique');
        });

        Schema::create('activity_competition_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('activity_competition_teams')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('role', 24)->default('MEMBER'); // MEMBER|CAPTAIN|RESERVE
            $table->boolean('receives_achievement')->default(true);
            $table->timestamps();
            $table->unique(['team_id', 'student_id'], 'activity_competition_members_unique');
        });

        Schema::create('activity_competition_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('activity_competitions')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('activity_competition_categories')->nullOnDelete();
            $table->string('name');
            $table->string('type', 24)->default('QUALIFICATION'); // QUALIFICATION|GROUP|QUARTER_FINAL|SEMI_FINAL|FINAL|FRIENDLY
            $table->integer('sequence')->default(1);
            $table->date('scheduled_date')->nullable();
            $table->string('location')->nullable();
            $table->string('status', 24)->default('SCHEDULED')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_competition_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('activity_competition_rounds')->cascadeOnDelete();
            $table->foreignId('competition_id')->constrained('activity_competitions')->cascadeOnDelete();
            $table->integer('match_number');
            $table->foreignId('participant_a_id')->nullable()->constrained('activity_competition_participants')->nullOnDelete();
            $table->foreignId('participant_b_id')->nullable()->constrained('activity_competition_participants')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('venue')->nullable();
            $table->string('status', 24)->default('SCHEDULED')->index(); // SCHEDULED|ONGOING|COMPLETED|CANCELLED|WALKOVER
            $table->decimal('score_a', 8, 2)->nullable();
            $table->decimal('score_b', 8, 2)->nullable();
            $table->foreignId('winner_participant_id')->nullable()->constrained('activity_competition_participants')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['round_id', 'match_number'], 'activity_competition_matches_unique');
        });

        Schema::create('activity_competition_judges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('activity_competitions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('name')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('activity_competition_categories')->nullOnDelete();
            $table->foreignId('round_id')->nullable()->constrained('activity_competition_rounds')->nullOnDelete();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['competition_id', 'user_id'], 'activity_competition_judges_unique');
        });

        Schema::create('activity_competition_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('activity_competitions')->cascadeOnDelete();
            $table->foreignId('match_id')->constrained('activity_competition_matches')->cascadeOnDelete();
            $table->foreignId('judge_id')->constrained('activity_competition_judges')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('activity_competition_participants')->cascadeOnDelete();
            $table->json('criteria_scores')->nullable();
            $table->decimal('total', 8, 2)->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 24)->default('DRAFT')->index(); // DRAFT|SUBMITTED|LOCKED|VOID
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('corrected_from_id')->nullable()->constrained('activity_competition_scores')->nullOnDelete();
            $table->text('correction_reason')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->string('idempotency_key', 96)->nullable();
            $table->timestamps();
            $table->unique(['match_id', 'judge_id', 'participant_id'], 'activity_competition_scores_unique');
            $table->unique(['idempotency_key'], 'activity_competition_scores_idempotency_unique');
        });

        Schema::create('activity_competition_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('activity_competitions')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('activity_competition_categories')->nullOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('activity_competition_participants')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('activity_competition_teams')->nullOnDelete();
            $table->integer('rank')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->string('award', 48)->nullable(); // GOLD|SILVER|BRONZE|WINNER|RUNNER_UP|FINALIST|PARTICIPANT|BEST_SPEAKER
            $table->string('status', 24)->default('DRAFT')->index(); // DRAFT|VERIFIED|PUBLISHED|DISPUTED
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['competition_id', 'category_id', 'participant_id', 'team_id'], 'activity_competition_results_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_competition_results');
        Schema::dropIfExists('activity_competition_scores');
        Schema::dropIfExists('activity_competition_judges');
        Schema::dropIfExists('activity_competition_matches');
        Schema::dropIfExists('activity_competition_rounds');
        Schema::dropIfExists('activity_competition_members');
        Schema::dropIfExists('activity_competition_teams');
        Schema::dropIfExists('activity_competition_participants');
        Schema::dropIfExists('activity_competition_categories');
        Schema::dropIfExists('activity_competitions');
    }
};
