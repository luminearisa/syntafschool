<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_registration_tables.
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
        Schema::create('competition_registration_forms', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->string('code', 60)->index();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->integer('version')->default(1);
            $t->string('status', 20)->index()->default('DRAFT');
            $t->boolean('is_active')->index()->default(true);
            $t->dateTime('published_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable()->index();
            $t->json('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_registration_fields', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('form_id')->index()->constrained('competition_registration_forms')->cascadeOnDelete();
            $t->string('field_code', 60)->index();
            $t->string('label', 200);
            $t->string('field_type', 30)->index()->default('TEXT');
            $t->boolean('is_required')->default(false);
            $t->boolean('is_system')->default(false);
            $t->json('options')->nullable();
            $t->json('validation_rules')->nullable();
            $t->string('conditional_on_field', 60)->nullable();
            $t->string('conditional_operator', 20)->nullable();
            $t->string('conditional_value', 150)->nullable();
            $t->integer('max_size_kb')->nullable();
            $t->string('allowed_mime_types', 255)->nullable();
            $t->string('placeholder', 150)->nullable();
            $t->string('help_text', 255)->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->index()->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_document_requirements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('category_id')->index()->constrained('competition_categories')->cascadeOnDelete();
            $t->unsignedBigInteger('edition_id')->nullable()->index();
            $t->string('document_type', 60)->index();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->boolean('is_required')->default(true);
            $t->boolean('allows_multiple')->default(false);
            $t->integer('max_size_kb')->nullable();
            $t->string('allowed_mime_types', 255)->nullable();
            $t->boolean('requires_verification')->default(true);
            $t->integer('expires_after_days')->nullable();
            $t->integer('sort_order')->default(0);
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_registrations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('edition_id')->index()->constrained('competition_editions')->cascadeOnDelete();
            $t->foreignId('category_id')->index()->constrained('competition_categories')->cascadeOnDelete();
            $t->unsignedBigInteger('form_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->string('registration_number', 60)->unique();
            $t->string('participant_type', 30)->index()->default('STUDENT');
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->unsignedBigInteger('submitted_by_user_id')->nullable()->index();
            $t->string('institution_name', 200)->nullable();
            $t->string('external_participant_name', 200)->nullable();
            $t->string('external_participant_email', 180)->nullable();
            $t->string('external_participant_phone', 40)->nullable();
            $t->string('external_participant_id_number', 80)->nullable();
            $t->string('status', 30)->index()->default('DRAFT');
            $t->string('eligibility_status', 30)->nullable()->index();
            $t->string('document_status', 30)->nullable()->index();
            $t->string('consent_status', 30)->nullable()->index();
            $t->string('payment_status', 20)->index()->default('NOT_REQUIRED');
            $t->decimal('fee_amount')->nullable();
            $t->string('currency', 10)->default('IDR');
            $t->string('finance_reference', 100)->nullable();
            $t->integer('waitlist_position')->nullable();
            $t->integer('priority')->default(0);
            $t->string('submitted_via', 20)->index()->default('WEB');
            $t->string('source', 30)->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->unsignedBigInteger('rejected_by')->nullable();
            $t->dateTime('rejected_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->text('revision_notes')->nullable();
            $t->text('withdrawal_reason')->nullable();
            $t->dateTime('withdrawn_at')->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->text('notes')->nullable();
            $t->string('idempotency_key', 120)->nullable()->index();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_registration_values', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('registration_id')->index()->constrained('competition_registrations')->cascadeOnDelete();
            $t->foreignId('field_id')->index()->constrained('competition_registration_fields')->cascadeOnDelete();
            $t->string('field_code', 60)->index();
            $t->text('value_text')->nullable();
            $t->decimal('value_number')->nullable();
            $t->date('value_date')->nullable();
            $t->json('value_json')->nullable();
            $t->string('file_path', 255)->nullable();
            $t->string('file_name', 200)->nullable();
            $t->integer('file_size')->nullable();
            $t->string('mime_type', 120)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_registration_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('registration_id')->index()->constrained('competition_registrations')->cascadeOnDelete();
            $t->unsignedBigInteger('requirement_id')->nullable()->index();
            $t->string('document_type', 60)->index();
            $t->string('file_path', 255);
            $t->string('file_name', 200)->nullable();
            $t->integer('file_size')->nullable();
            $t->string('mime_type', 120)->nullable();
            $t->string('checksum', 64)->nullable()->index();
            $t->string('status', 20)->index()->default('PENDING');
            $t->integer('version')->default(1);
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->dateTime('verified_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->string('virus_scan_status', 20)->index()->default('PENDING');
            $t->dateTime('virus_scanned_at')->nullable();
            $t->date('expires_on')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_registration_status_histories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('registration_id')->index()->constrained('competition_registrations')->cascadeOnDelete();
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30)->index();
            $t->text('reason')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 150)->nullable();
            $t->string('source', 20)->default('WEB');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_consents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('registration_id')->index()->constrained('competition_registrations')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->string('consent_type', 40)->index()->default('PARENT');
            $t->integer('version')->default(1);
            $t->string('status', 20)->index()->default('PENDING');
            $t->unsignedBigInteger('requested_from_user_id')->nullable()->index();
            $t->string('guardian_name', 200)->nullable();
            $t->string('guardian_contact', 60)->nullable();
            $t->string('guardian_relation', 40)->nullable();
            $t->unsignedBigInteger('responded_by')->nullable();
            $t->dateTime('responded_at')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('document_path', 255)->nullable();
            $t->text('signature_data')->nullable();
            $t->text('response_note')->nullable();
            $t->text('rejected_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_eligibility_evaluations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('registration_id')->index()->constrained('competition_registrations')->cascadeOnDelete();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->string('result', 30)->index()->default('PENDING');
            $t->decimal('score')->nullable();
            $t->integer('rules_evaluated')->default(0);
            $t->integer('rules_passed')->default(0);
            $t->integer('rules_failed')->default(0);
            $t->json('reasons')->nullable();
            $t->json('evidence')->nullable();
            $t->string('engine_version', 20)->default('1.0');
            $t->unsignedBigInteger('evaluated_by')->nullable();
            $t->dateTime('evaluated_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_eligibility_rule_results', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('evaluation_id')->index()->constrained('competition_eligibility_evaluations')->cascadeOnDelete();
            $t->unsignedBigInteger('rule_id')->nullable()->index();
            $t->string('rule_code', 60)->index();
            $t->string('rule_type', 40)->nullable();
            $t->boolean('passed')->index()->default(false);
            $t->string('actual_value', 255)->nullable();
            $t->string('expected_value', 255)->nullable();
            $t->string('message', 255)->nullable();
            $t->decimal('weight')->nullable();
            $t->decimal('contributed_score')->nullable();
            $t->json('evidence')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_eligibility_rule_results');
        Schema::dropIfExists('competition_eligibility_evaluations');
        Schema::dropIfExists('competition_consents');
        Schema::dropIfExists('competition_registration_status_histories');
        Schema::dropIfExists('competition_registration_documents');
        Schema::dropIfExists('competition_registration_values');
        Schema::dropIfExists('competition_registrations');
        Schema::dropIfExists('competition_document_requirements');
        Schema::dropIfExists('competition_registration_fields');
        Schema::dropIfExists('competition_registration_forms');
    }
};
