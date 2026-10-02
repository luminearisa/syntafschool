<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_support_tables.
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
        Schema::create('competition_templates', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 60)->unique();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('competition_type_id')->nullable()->index();
            $t->unsignedBigInteger('format_id')->nullable()->index();
            $t->unsignedBigInteger('competition_domain_id')->nullable()->index();
            $t->string('default_participant_mode', 20)->default('INDIVIDUAL');
            $t->string('default_scoring_model', 40)->default('WEIGHTED');
            $t->string('default_ranking_model', 40)->default('HIGHER_BETTER');
            $t->string('default_rubric_code', 60)->nullable();
            $t->boolean('requires_eligibility')->default(true);
            $t->boolean('requires_parent_consent')->default(false);
            $t->boolean('requires_selection')->default(false);
            $t->json('settings')->nullable();
            $t->integer('usage_count')->default(0);
            $t->boolean('is_active')->index()->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_template_categories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('template_id')->index()->constrained('competition_templates')->cascadeOnDelete();
            $t->string('code', 60)->index();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('competition_division_id')->nullable()->index();
            $t->string('participant_mode', 20)->default('INDIVIDUAL');
            $t->integer('min_team_size')->nullable();
            $t->integer('max_team_size')->nullable();
            $t->integer('capacity')->nullable();
            $t->string('scoring_model', 40)->nullable();
            $t->string('ranking_model', 40)->nullable();
            $t->string('rubric_code', 60)->nullable();
            $t->json('settings')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_template_rules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('template_id')->index()->constrained('competition_templates')->cascadeOnDelete();
            $t->string('category_code', 60)->nullable()->index();
            $t->string('rules_type', 40)->index()->default('CONDUCT');
            $t->string('code', 60)->nullable();
            $t->string('name', 200);
            $t->text('content')->nullable();
            $t->boolean('is_mandatory')->default(true);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_template_rounds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('template_id')->index()->constrained('competition_templates')->cascadeOnDelete();
            $t->string('category_code', 60)->nullable()->index();
            $t->string('code', 60)->nullable();
            $t->string('name', 200);
            $t->integer('sequence')->default(1);
            $t->string('round_type', 40)->default('QUALIFICATION');
            $t->string('format', 40)->default('SINGLE');
            $t->string('advancement_rule', 40)->nullable();
            $t->integer('qualification_count')->nullable();
            $t->json('settings')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_operations_checklists', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->string('phase', 30)->index()->default('BEFORE');
            $t->string('code', 60)->index();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->boolean('is_mandatory')->index()->default(true);
            $t->unsignedBigInteger('owner_employee_id')->nullable()->index();
            $t->string('status', 20)->index()->default('PENDING');
            $t->dateTime('due_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->unsignedBigInteger('completed_by')->nullable();
            $t->integer('sort_order')->default(0);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_budget_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->string('budget_category', 40)->index()->default('OTHER');
            $t->string('code', 60)->nullable();
            $t->string('name', 200);
            $t->decimal('planned_amount')->default(0);
            $t->decimal('committed_amount')->nullable();
            $t->decimal('actual_amount')->nullable();
            $t->string('currency', 10)->default('IDR');
            $t->string('finance_reference', 120)->nullable();
            $t->string('status', 20)->index()->default('PLANNED');
            $t->text('notes')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_sponsors', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->string('name', 200);
            $t->string('sponsor_type', 40)->index()->default('OTHER');
            $t->string('contact_name', 200)->nullable();
            $t->string('contact_email', 180)->nullable();
            $t->string('contact_phone', 40)->nullable();
            $t->decimal('pledged_amount')->nullable();
            $t->decimal('received_amount')->nullable();
            $t->string('currency', 10)->default('IDR');
            $t->string('finance_reference', 120)->nullable();
            $t->string('status', 20)->index()->default('PLEDGED');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_resource_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->unsignedBigInteger('round_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->unsignedBigInteger('match_id')->nullable()->index();
            $t->string('resource_type', 40)->index()->default('FACILITY');
            $t->string('provider_name', 40)->nullable();
            $t->string('provider_reference', 120)->nullable()->index();
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->dateTime('requested_at')->nullable();
            $t->decimal('quantity')->nullable();
            $t->string('unit', 30)->nullable();
            $t->dateTime('needed_from')->nullable();
            $t->dateTime('needed_to')->nullable();
            $t->string('status', 20)->index()->default('REQUESTED');
            $t->dateTime('fulfilled_at')->nullable();
            $t->dateTime('returned_at')->nullable();
            $t->text('reason')->nullable();
            $t->json('payload')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_external_results', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('campus_id')->nullable()->index();
            $t->string('participant_name', 200);
            $t->string('competition_name', 200);
            $t->string('organizer_name', 200)->nullable();
            $t->string('category_name', 200)->nullable();
            $t->string('level_code', 40)->nullable()->index();
            $t->integer('position')->nullable();
            $t->string('award_label', 120)->nullable();
            $t->decimal('score')->nullable();
            $t->date('participated_on')->nullable()->index();
            $t->string('evidence_path', 255)->nullable();
            $t->string('certificate_number', 120)->nullable();
            $t->string('verification_url', 255)->nullable();
            $t->string('status', 20)->index()->default('DRAFT');
            $t->unsignedBigInteger('imported_by')->nullable();
            $t->dateTime('imported_at')->nullable();
            $t->string('achievement_reference', 120)->nullable();
            $t->dateTime('achievement_synced_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_import_batches', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('edition_id')->nullable()->index();
            $t->string('import_type', 40)->index()->default('PARTICIPANT');
            $t->string('file_name', 200)->nullable();
            $t->string('file_path', 255)->nullable();
            $t->integer('total_rows')->default(0);
            $t->integer('processed_rows')->default(0);
            $t->integer('failed_rows')->default(0);
            $t->string('status', 20)->index()->default('PENDING');
            $t->json('error_summary')->nullable();
            $t->unsignedBigInteger('imported_by')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_import_rows', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('batch_id')->index()->constrained('competition_import_batches')->cascadeOnDelete();
            $t->integer('row_number')->index();
            $t->json('payload')->nullable();
            $t->string('status', 20)->index()->default('PENDING');
            $t->text('error_message')->nullable();
            $t->string('created_entity_type', 150)->nullable();
            $t->unsignedBigInteger('created_entity_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_reports', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->string('report_type', 40)->index()->default('COMPETITION');
            $t->string('code', 60)->index();
            $t->string('name', 200);
            $t->string('status', 20)->index()->default('DRAFT');
            $t->string('format', 20)->default('PDF');
            $t->json('parameters')->nullable();
            $t->json('summary')->nullable();
            $t->string('file_path', 255)->nullable();
            $t->unsignedBigInteger('generated_by')->nullable();
            $t->dateTime('generated_at')->nullable();
            $t->dateTime('published_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_kpis', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->string('kpi_code', 60)->index();
            $t->string('name', 200);
            $t->string('unit', 30)->nullable();
            $t->string('direction', 30)->default('HIGHER_BETTER');
            $t->decimal('weight')->nullable();
            $t->decimal('target_value')->nullable();
            $t->decimal('actual_value')->nullable();
            $t->dateTime('measured_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_public_pages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->string('slug', 220)->unique();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->text('content')->nullable();
            $t->json('faq')->nullable();
            $t->json('contact')->nullable();
            $t->text('venue_information')->nullable();
            $t->json('timeline')->nullable();
            $t->boolean('is_published')->index()->default(false);
            $t->dateTime('published_at')->nullable();
            $t->integer('view_count')->default(0);
            $t->json('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_public_pages');
        Schema::dropIfExists('competition_kpis');
        Schema::dropIfExists('competition_reports');
        Schema::dropIfExists('competition_import_rows');
        Schema::dropIfExists('competition_import_batches');
        Schema::dropIfExists('competition_external_results');
        Schema::dropIfExists('competition_resource_requests');
        Schema::dropIfExists('competition_sponsors');
        Schema::dropIfExists('competition_budget_items');
        Schema::dropIfExists('competition_operations_checklists');
        Schema::dropIfExists('competition_template_rounds');
        Schema::dropIfExists('competition_template_rules');
        Schema::dropIfExists('competition_template_categories');
        Schema::dropIfExists('competition_templates');
    }
};
