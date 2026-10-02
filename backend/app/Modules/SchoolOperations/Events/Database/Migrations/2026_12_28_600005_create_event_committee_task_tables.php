<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Event (School Operations Fase 4) — 600005_create_event_committee_task_tables.
 *
 * Catatan: kolom `_id` diberi indeks; kunci asing keras hanya dipakai pada
 * tabel anak yang memang terikat siklus hidup induknya (cascade), sehingga
 * pengujian lintas modul tidak bergantung urutan penghapusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_committees', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->json('structure')->nullable();
            $t->unsignedBigInteger('chair_person_id')->nullable()->index();
            $t->unsignedBigInteger('secretary_id')->nullable();
            $t->unsignedBigInteger('treasurer_id')->nullable();
            $t->integer('member_count')->default(0);
            $t->date('established_at')->nullable();
            $t->date('dissolved_at')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_committee_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('committee_id')->index()->constrained('event_committees')->cascadeOnDelete();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('name', 150);
            $t->string('position', 60)->index()->default('MEMBER');
            $t->string('position_title', 150)->nullable();
            $t->string('division', 60)->nullable();
            $t->string('role_code', 60)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->boolean('is_leader')->index()->default(false);
            $t->unsignedBigInteger('replaced_by')->nullable();
            $t->date('joined_at')->nullable();
            $t->date('left_at')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_volunteers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('committee_id')->nullable()->index();
            $t->string('volunteer_type', 30)->index()->default('STUDENT');
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('name', 150);
            $t->string('assignment', 40)->index()->default('REGISTRATION');
            $t->string('division', 60)->nullable();
            $t->dateTime('shift_start')->nullable();
            $t->dateTime('shift_end')->nullable();
            $t->decimal('hours_planned')->nullable();
            $t->decimal('hours_actual')->nullable();
            $t->boolean('briefing_attended')->default(false);
            $t->boolean('certificate_eligible')->default(false);
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_tasks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('committee_id')->nullable()->index();
            $t->unsignedBigInteger('parent_task_id')->nullable()->index();
            $t->string('task_code', 50)->nullable();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('category', 40)->nullable();
            $t->string('priority', 20)->index()->default('NORMAL');
            $t->unsignedBigInteger('owner_id')->nullable()->index();
            $t->string('owner_name', 150)->nullable();
            $t->string('owner_role', 60)->nullable();
            $t->string('division', 60)->nullable();
            $t->date('start_date')->nullable();
            $t->date('due_date')->nullable()->index();
            $t->dateTime('completed_at')->nullable();
            $t->integer('progress')->default(0);
            $t->string('status', 20)->index()->default('TODO');
            $t->text('blocked_reason')->nullable();
            $t->unsignedBigInteger('depends_on_task_id')->nullable()->index();
            $t->boolean('is_mandatory')->default(false);
            $t->unsignedBigInteger('checklist_id')->nullable();
            $t->integer('sort_order')->default(0);
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_task_dependencies', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('task_id')->index()->constrained('event_tasks')->cascadeOnDelete();
            $t->unsignedBigInteger('depends_on_task_id')->index();
            $t->string('dependency_type', 20)->default('FINISH_TO_START');
            $t->boolean('is_blocking')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_checklists', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('checklist_type', 40)->index();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->integer('total_items')->default(0);
            $t->integer('completed_items')->default(0);
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->dateTime('verified_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_checklist_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('checklist_id')->index()->constrained('event_checklists')->cascadeOnDelete();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->string('item_code', 60)->nullable();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('category', 40)->nullable();
            $t->boolean('is_mandatory')->index()->default(false);
            $t->boolean('is_required_before_ready')->default(false);
            $t->unsignedBigInteger('responsible_id')->nullable()->index();
            $t->string('responsible_name', 150)->nullable();
            $t->dateTime('due_at')->nullable();
            $t->boolean('checked')->index()->default(false);
            $t->unsignedBigInteger('checked_by')->nullable();
            $t->dateTime('checked_at')->nullable();
            $t->text('checked_note')->nullable();
            $t->integer('sort_order')->default(0);
            $t->string('status', 20)->index()->default('PENDING');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_incident_actions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('incident_id')->index();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->string('action_type', 30)->default('RESPONSE');
            $t->text('description');
            $t->unsignedBigInteger('taken_by')->nullable()->index();
            $t->string('taken_by_name', 150)->nullable();
            $t->dateTime('taken_at')->nullable();
            $t->text('outcome')->nullable();
            $t->string('status', 20)->default('DONE');
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_incident_actions');
        Schema::dropIfExists('event_checklist_items');
        Schema::dropIfExists('event_checklists');
        Schema::dropIfExists('event_task_dependencies');
        Schema::dropIfExists('event_tasks');
        Schema::dropIfExists('event_volunteers');
        Schema::dropIfExists('event_committee_members');
        Schema::dropIfExists('event_committees');
    }
};
