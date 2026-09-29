<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 Modul Supervisi — program, penugasan, dan jadwal supervisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sup_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('semester_id')->nullable();
            $table->string('title', 180);
            $table->string('scope', 20)->default('AKADEMIK');      // AKADEMIK | MANAJERIAL | GABUNGAN
            $table->text('objective')->nullable();
            $table->text('background')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->text('budget_note')->nullable();
            $table->json('technique_plan')->nullable();            // rencana teknik (kunjungan kelas, dll.)
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at'], 'sup_program_status_idx');
        });

        Schema::create('sup_program_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained('sup_programs')->cascadeOnDelete();
            $table->string('target_type', 30);                     // DEPARTMENT | SUBJECT | UNIT | EMPLOYEE_TYPE | SCHOOL
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedInteger('planned_count')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['program_id', 'target_type'], 'sup_target_idx');
        });

        Schema::create('sup_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained('sup_programs')->cascadeOnDelete();
            $table->unsignedBigInteger('supervisor_employee_id');
            $table->unsignedBigInteger('supervisee_employee_id');
            $table->string('role', 20)->default('UTAMA');
            $table->string('approach', 30)->default('COLLABORATIVE');
            $table->string('scope_type', 30)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->json('allowed_instruments')->nullable();       // daftar instrument_id
            $table->unsignedInteger('min_visits')->default(1);
            $table->unsignedInteger('target_visits')->nullable();
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'supervisor_employee_id', 'supervisee_employee_id', 'role'], 'sup_assignment_unique');
            $table->index(['supervisee_employee_id', 'is_active'], 'sup_assignment_supervisee_idx');
            $table->index(['supervisor_employee_id', 'is_active'], 'sup_assignment_supervisor_idx');
        });

        Schema::create('sup_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained('sup_programs')->cascadeOnDelete();
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->unsignedBigInteger('supervisor_employee_id');
            $table->unsignedBigInteger('supervisee_employee_id');
            $table->string('activity_kind', 30)->default('CLASS_OBSERVATION');
            $table->unsignedBigInteger('instrument_version_id')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('class_id')->nullable();
            $table->string('focus', 500)->nullable();
            $table->timestamp('planned_at');
            $table->timestamp('window_end')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('status', 20)->default('PLANNED');
            $table->text('cancel_reason')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supervisee_employee_id', 'planned_at'], 'sup_schedule_supervisee_idx');
            $table->index(['supervisor_employee_id', 'planned_at'], 'sup_schedule_supervisor_idx');
            $table->index(['status', 'planned_at'], 'sup_schedule_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sup_schedules');
        Schema::dropIfExists('sup_assignments');
        Schema::dropIfExists('sup_program_targets');
        Schema::dropIfExists('sup_programs');
    }
};
