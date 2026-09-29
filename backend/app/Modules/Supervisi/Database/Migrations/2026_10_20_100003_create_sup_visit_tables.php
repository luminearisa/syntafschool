<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 Modul Supervisi — kegiatan supervisi (jantung modul).
 *
 * Skema disiapkan lengkap sejak Fase 1 supaya mesin penskoran, RTL, dan
 * pelaporan pada fase berikutnya tidak perlu migrasi tambahan yang mengubah
 * tabel yang sudah berisi data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sup_visits', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();                  // SUP/2026/000123
            $table->foreignId('program_id')->nullable()->constrained('sup_programs')->nullOnDelete();
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->unsignedBigInteger('supervisor_employee_id');
            $table->unsignedBigInteger('supervisee_employee_id');
            $table->string('activity_kind', 30)->default('CLASS_OBSERVATION');
            $table->unsignedBigInteger('instrument_version_id')->nullable();
            $table->string('approach', 30)->default('COLLABORATIVE');
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('class_id')->nullable();
            $table->string('lesson_topic', 200)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->timestamp('pre_conference_at')->nullable();
            $table->timestamp('post_conference_at')->nullable();

            // Hasil hitung (server) — tidak pernah diisi manual dari frontend.
            $table->decimal('raw_score', 10, 2)->nullable();
            $table->decimal('max_score', 10, 2)->nullable();
            $table->decimal('final_score', 10, 2)->nullable();
            $table->string('grade_band', 30)->nullable();
            $table->string('grade_label', 60)->nullable();
            $table->string('grade_tone', 10)->nullable();

            $table->text('summary')->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->json('snapshot')->nullable();                   // instrumen, bobot, ambang saat finalisasi
            $table->timestamp('scored_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->boolean('is_disputed')->default(false);
            $table->string('client_uuid', 64)->nullable();          // sinkronisasi mode luring
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supervisee_employee_id', 'observed_at'], 'sup_visit_supervisee_idx');
            $table->index(['supervisor_employee_id', 'status'], 'sup_visit_supervisor_idx');
            $table->index(['program_id', 'status'], 'sup_visit_program_idx');
            $table->index(['instrument_version_id', 'status'], 'sup_visit_version_idx');
        });

        Schema::create('sup_visit_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('sup_visits')->cascadeOnDelete();
            $table->unsignedBigInteger('indicator_id');
            $table->unsignedBigInteger('option_id')->nullable();     // untuk SCALE / MULTI_CHOICE
            $table->decimal('score', 10, 2)->nullable();
            $table->string('value_text', 500)->nullable();
            $table->text('value_long_text')->nullable();
            $table->decimal('value_number', 12, 2)->nullable();
            $table->date('value_date')->nullable();
            $table->json('value_json')->nullable();                  // MULTI_CHOICE: daftar option_id
            $table->boolean('is_na')->default(false);
            $table->text('comment')->nullable();
            $table->string('evidence_note', 500)->nullable();
            $table->unsignedBigInteger('answered_by')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->string('client_uuid', 64)->nullable();
            $table->timestamps();

            $table->unique(['visit_id', 'indicator_id'], 'sup_answer_unique');
            $table->index('indicator_id', 'sup_answer_indicator_idx');
        });

        Schema::create('sup_visit_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('sup_visits')->cascadeOnDelete();
            $table->unsignedBigInteger('section_id');
            $table->decimal('raw_score', 10, 2)->default(0);
            $table->decimal('max_score', 10, 2)->default(0);
            $table->decimal('weighted_score', 10, 2)->nullable();
            $table->decimal('percent', 8, 2)->nullable();
            $table->string('band', 30)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['visit_id', 'section_id'], 'sup_visit_score_unique');
        });

        Schema::create('sup_visit_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('sup_visits')->cascadeOnDelete();
            $table->unsignedBigInteger('visit_answer_id')->nullable();
            $table->string('kind', 20)->default('DOCUMENT');
            $table->string('path', 255)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('caption', 255)->nullable();
            $table->string('visibility', 20)->default('SUPERVISEE');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'visibility'], 'sup_evidence_visit_idx');
        });

        Schema::create('sup_visit_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('sup_visits')->cascadeOnDelete();
            $table->string('note_type', 20)->default('FACT');        // FACT | TIMESTAMP | RECOMMENDATION | PRIVATE
            $table->unsignedInteger('at_seconds')->nullable();
            $table->text('body');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'note_type'], 'sup_note_idx');
        });

        Schema::create('sup_conferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('sup_visits')->cascadeOnDelete();
            $table->string('kind', 10);                              // PRE | POST
            $table->timestamp('held_at')->nullable();
            $table->string('location', 120)->nullable();
            $table->json('participants')->nullable();
            $table->json('answers')->nullable();                     // pertanyaan baku + jawaban
            $table->json('agreements')->nullable();                  // fokus/kesepakatan
            $table->text('reflection')->nullable();                  // refleksi guru
            $table->string('next_focus', 300)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['visit_id', 'kind'], 'sup_conference_unique');
        });

        Schema::create('sup_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('sup_visits')->cascadeOnDelete();
            $table->string('component', 40)->default('PELAKSANAAN');
            $table->text('strength')->nullable();
            $table->text('weakness')->nullable();
            $table->text('problem')->nullable();
            $table->text('cause')->nullable();
            $table->string('priority', 20)->default('MEDIUM');
            $table->text('recommendation')->nullable();
            $table->unsignedBigInteger('indicator_id')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'priority'], 'sup_finding_idx');
        });

        Schema::create('sup_acknowledgments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('sup_visits')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('signature_hash', 64)->nullable();
            $table->text('note')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->unique(['visit_id', 'employee_id'], 'sup_ack_unique');
        });

        Schema::create('sup_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('sup_visits')->cascadeOnDelete();
            $table->unsignedBigInteger('raised_by_employee_id');
            $table->text('reason');
            $table->json('proposed_scores')->nullable();
            $table->json('attachments')->nullable();
            $table->string('status', 30)->default('OPEN');
            $table->unsignedBigInteger('moderator_employee_id')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'status'], 'sup_dispute_idx');
        });

        Schema::create('sup_visit_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('sup_visits')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 40);                            // finalized, reopened, rescored
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'revision'], 'sup_revision_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sup_visit_revisions');
        Schema::dropIfExists('sup_disputes');
        Schema::dropIfExists('sup_acknowledgments');
        Schema::dropIfExists('sup_findings');
        Schema::dropIfExists('sup_conferences');
        Schema::dropIfExists('sup_visit_notes');
        Schema::dropIfExists('sup_visit_evidence');
        Schema::dropIfExists('sup_visit_scores');
        Schema::dropIfExists('sup_visit_answers');
        Schema::dropIfExists('sup_visits');
    }
};
