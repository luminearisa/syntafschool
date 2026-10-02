<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Event (School Operations Fase 4) — 600001_create_event_core_tables.
 *
 * Catatan: kolom `_id` diberi indeks; kunci asing keras hanya dipakai pada
 * tabel anak yang memang terikat siklus hidup induknya (cascade), sehingga
 * pengujian lintas modul tidak bergantung urutan penghapusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_event_types', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 150);
            $t->string('slug', 170)->unique();
            $t->text('description')->nullable();
            $t->string('group', 40)->nullable();
            $t->string('color', 20)->nullable();
            $t->string('icon', 40)->nullable();
            $t->string('default_visibility', 30)->default('INTERNAL');
            $t->boolean('requires_proposal')->default(true);
            $t->boolean('requires_budget')->default(true);
            $t->boolean('requires_approval')->default(true);
            $t->boolean('requires_consent')->default(false);
            $t->boolean('requires_committee')->default(false);
            $t->boolean('allow_public_registration')->default(false);
            $t->boolean('allow_paid_registration')->default(false);
            $t->integer('default_capacity')->nullable();
            $t->string('workflow_code', 60)->nullable();
            $t->integer('sort_order')->default(0);
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->boolean('is_active')->index()->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_categories', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 100);
            $t->text('description')->nullable();
            $t->boolean('is_public')->default(false);
            $t->boolean('requires_invitation')->default(false);
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->index()->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organization_id')->nullable()->index();
            $t->unsignedBigInteger('campus_id')->nullable()->index();
            $t->unsignedBigInteger('school_event_type_id')->index();
            $t->unsignedBigInteger('event_category_id')->nullable()->index();
            $t->unsignedBigInteger('template_id')->nullable()->index();
            $t->unsignedBigInteger('recurrence_id')->nullable()->index();
            $t->unsignedBigInteger('parent_event_id')->nullable()->index();
            $t->string('code', 50)->unique();
            $t->string('name', 200);
            $t->string('slug', 220)->unique();
            $t->text('description')->nullable();
            $t->string('summary', 500)->nullable();
            $t->string('event_category', 30)->index()->default('INTERNAL');
            $t->string('visibility', 30)->index()->default('INTERNAL');
            $t->string('status', 30)->index()->default('DRAFT');
            $t->string('priority', 20)->default('NORMAL');
            $t->string('risk_level', 20)->index()->default('LOW');
            $t->date('start_date')->index();
            $t->date('end_date')->nullable()->index();
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->string('timezone', 60)->default('Asia/Jakarta');
            $t->boolean('is_multi_day')->default(false);
            $t->boolean('is_recurring')->default(false);
            $t->boolean('is_public')->default(false);
            $t->boolean('requires_registration')->default(false);
            $t->boolean('requires_consent')->default(false);
            $t->boolean('requires_ticket')->default(false);
            $t->boolean('is_paid')->default(false);
            $t->decimal('ticket_price')->nullable();
            $t->integer('min_participants')->nullable();
            $t->integer('max_participants')->nullable();
            $t->integer('expected_participants')->nullable();
            $t->integer('actual_participants')->nullable();
            $t->boolean('waitlist_enabled')->default(false);
            $t->integer('waitlist_capacity')->nullable();
            $t->unsignedBigInteger('organizer_id')->nullable()->index();
            $t->unsignedBigInteger('event_manager_id')->nullable()->index();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('published_at')->nullable();
            $t->dateTime('registration_open_at')->nullable();
            $t->dateTime('registration_close_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->integer('version_number')->default(1);
            $t->unsignedBigInteger('current_venue_id')->nullable()->index();
            $t->string('primary_location', 255)->nullable();
            $t->string('backup_location', 255)->nullable();
            $t->unsignedBigInteger('public_page_id')->nullable();
            $t->decimal('budget_total')->default(0);
            $t->decimal('budget_committed')->default(0);
            $t->decimal('budget_actual')->default(0);
            $t->string('currency', 10)->default('IDR');
            $t->boolean('weather_dependency')->default(false);
            $t->string('cancellation_threshold', 100)->nullable();
            $t->json('tags')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('event_id')->index();
            $t->integer('version_number')->default(1);
            $t->json('snapshot')->nullable();
            $t->text('change_summary')->nullable();
            $t->unsignedBigInteger('changed_by')->nullable()->index();
            $t->string('change_reason', 255)->nullable();
            $t->dateTime('changed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_proposals', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->string('code', 50)->unique();
            $t->string('title', 200);
            $t->text('background')->nullable();
            $t->text('objective')->nullable();
            $t->text('target_audience')->nullable();
            $t->integer('expected_participants')->nullable();
            $t->date('proposed_date')->index();
            $t->date('proposed_end_date')->nullable();
            $t->string('proposed_location', 255)->nullable();
            $t->decimal('estimated_budget')->nullable();
            $t->string('risk_level', 20)->index()->default('LOW');
            $t->unsignedBigInteger('organizer_id')->nullable()->index();
            $t->text('organizer_note')->nullable();
            $t->unsignedBigInteger('proposal_document_id')->nullable();
            $t->string('status', 30)->index()->default('DRAFT');
            $t->unsignedBigInteger('submitted_by')->nullable()->index();
            $t->dateTime('submitted_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->unsignedBigInteger('converted_event_id')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_approvals', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->unsignedBigInteger('proposal_id')->nullable()->index();
            $t->unsignedBigInteger('workflow_instance_id')->nullable()->index();
            $t->string('step_code', 60)->nullable();
            $t->string('step_name', 150)->nullable();
            $t->integer('approval_level')->default(1);
            $t->string('approver_role', 60)->nullable();
            $t->unsignedBigInteger('approver_id')->nullable()->index();
            $t->string('decision', 20)->index()->default('PENDING');
            $t->dateTime('decided_at')->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('delegated_to')->nullable();
            $t->integer('sequence')->default(1);
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_objectives', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('event_id')->index();
            $t->string('objective_type', 40)->index();
            $t->string('title', 200)->nullable();
            $t->text('description')->nullable();
            $t->integer('target_value')->nullable();
            $t->integer('actual_value')->nullable();
            $t->string('unit', 30)->nullable();
            $t->integer('weight')->default(1);
            $t->string('status', 20)->default('ACTIVE');
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_targets', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('event_id')->index();
            $t->string('target_type', 40)->index();
            $t->string('scope_type', 40)->nullable();
            $t->unsignedBigInteger('scope_id')->nullable()->index();
            $t->json('scope_ids')->nullable();
            $t->string('description', 255)->nullable();
            $t->json('include_filter')->nullable();
            $t->json('exclude_filter')->nullable();
            $t->integer('estimated_count')->nullable();
            $t->integer('resolved_count')->nullable();
            $t->string('resolution_mode', 20)->default('DYNAMIC');
            $t->dateTime('resolved_at')->nullable();
            $t->string('status', 20)->default('ACTIVE');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_templates', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('school_event_type_id')->nullable()->index();
            $t->string('code', 50)->unique();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->json('default_program')->nullable();
            $t->json('default_checklist')->nullable();
            $t->json('default_roles')->nullable();
            $t->json('default_tasks')->nullable();
            $t->json('default_budget_items')->nullable();
            $t->json('default_resources')->nullable();
            $t->json('default_notifications')->nullable();
            $t->integer('default_duration_days')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->boolean('is_active')->index()->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_template_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('template_id')->index()->constrained('event_templates')->cascadeOnDelete();
            $t->string('item_type', 30)->index();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->json('payload')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_template_items');
        Schema::dropIfExists('event_templates');
        Schema::dropIfExists('event_targets');
        Schema::dropIfExists('event_objectives');
        Schema::dropIfExists('event_approvals');
        Schema::dropIfExists('event_proposals');
        Schema::dropIfExists('event_versions');
        Schema::dropIfExists('events');
        Schema::dropIfExists('event_categories');
        Schema::dropIfExists('school_event_types');
    }
};
