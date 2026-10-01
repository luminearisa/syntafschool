<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Umpan balik, SLA & eskalasi, serta kebijakan retensi.
 *
 * SLA dan retensi adalah **data**, bukan konstanta: sekolah mengubahnya tanpa
 * deploy, dan setiap perubahan berlaku sebagai versi baru.
 *
 * Catatan desain: kolom status/kategori memakai `string` dengan daftar nilai yang
 * dijaga PHP Enum, bukan `enum()` basis data. Alasannya: modul BK sudah menambah
 * nilai beberapa kali, dan CHECK constraint bawaan `enum()` membuat penambahan
 * nilai baru selalu berarti migrasi pemecahan tabel — di MySQL maupun SQLite.
 * Validasi tetap ada, hanya dipindahkan ke tempat yang benar (enum + Form Request).
 *
 * `school_id` nullable adalah penopang isolasi unit sekolah. Instalasi satu
 * sekolah membiarkannya kosong dan perilaku modelnya sama seperti sebelumnya;
 * instalasi yayasan dengan beberapa unit mengisinya, dan global scope
 * `BelongsToSchool` menutup data antar-unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bk_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            // Umpan balik menempel pada objeknya (sesi, intervensi, program, penutupan
            // kasus, layanan konseling, pertemuan orang tua, tindak lanjut).
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->string('submitted_by_type', 16);                 // STUDENT|PARENT|GUARDIAN|TEACHER|OTHER
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_anonymous')->default(false);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();
            /*
             | Setelah FINAL, umpan balik tidak dapat diubah siapa pun — termasuk
             | konselor. Kolom ini yang membekukannya, bukan sekadar aturan tampilan.
             */
            $table->string('status', 20)->default('SUBMITTED');      // SUBMITTED|FINAL|WITHDRAWN
            $table->timestamp('submitted_at');
            $table->timestamp('finalized_at')->nullable();
            $table->boolean('visible_to_student')->default(true);
            $table->boolean('visible_to_counselor')->default(true);
            $table->string('confidentiality_level', 24)->default('STAFF_ONLY');
            $table->json('context')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'bk_feedback_subject');
            $table->index(['student_id', 'submitted_at'], 'bk_feedback_student');
            $table->index(['guardian_id', 'submitted_at'], 'bk_feedback_guardian');
            $table->index(['school_id', 'status'], 'bk_feedback_school');
        });

        Schema::create('bk_sla_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('code', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('name', 160);
            /*
             | SLA configurable per kategori/prioritas/severity/tipe kasus, bukan
             | angka yang ditanam di kode. `applies_to` menyaring cakupannya.
             */
            $table->string('subject_type', 24);                      // REFERRAL|SCREENING|ASSIGNMENT|FOLLOW_UP|CASE|URGENT_CASE|EXTERNAL_REFERRAL|CONSENT
            $table->json('applies_to')->nullable();                  // {"priorities":["URGENT"],"case_types":[],"categories":[],"severities":[]}
            $table->unsignedInteger('response_hours')->default(24);
            $table->unsignedInteger('resolution_hours')->nullable();
            $table->unsignedInteger('warn_before_hours')->default(4);
            $table->string('escalation_level', 24)->default('BK_COORDINATOR');  // COUNSELOR|BK_COORDINATOR|MANAGEMENT
            $table->string('second_escalation_level', 24)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'code', 'version'], 'bk_sla_policies_version');
            $table->index(['subject_type', 'is_active'], 'bk_sla_policies_subject');
        });

        Schema::create('bk_sla_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('bk_sla_policies')->nullOnDelete();
            $table->string('policy_code', 64)->nullable();
            $table->unsignedInteger('policy_version')->nullable();
            $table->string('subject_type', 24);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('bk_referrals')->nullOnDelete();
            $table->string('priority', 16)->nullable();
            $table->string('severity', 16)->nullable();
            $table->string('case_type', 32)->nullable();
            $table->string('case_category', 32)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('due_at');
            $table->timestamp('warn_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('breached_at')->nullable();
            $table->string('status', 20)->default('RUNNING');        // RUNNING|MET|BREACHED|ESCALATED|PAUSED|CANCELLED
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('current_level', 24)->default('NONE');
            $table->boolean('warning_sent')->default(false);
            $table->json('policy_snapshot')->nullable();
            $table->json('context')->nullable();
            /*
             | Idempotensi: satu SLA per (subject_type, subject_id, policy) —
             | menjalankan pemantauan berkali-kali tidak boleh melahirkan SLA baru
             | atau eskalasi ganda untuk pekerjaan yang sama.
             */
            $table->string('dedup_key', 191)->nullable()->unique();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'bk_sla_events_subject');
            $table->index(['status', 'due_at'], 'bk_sla_events_due');
            $table->index(['school_id', 'status'], 'bk_sla_events_school');
        });

        Schema::create('bk_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('subject_type', 24);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('sla_event_id')->nullable()->constrained('bk_sla_events')->nullOnDelete();
            // Counselor → BK Coordinator → Management.
            $table->string('from_level', 24)->default('NONE');
            $table->string('to_level', 24);
            $table->text('reason')->nullable();
            $table->json('triggers')->nullable();
            $table->string('status', 20)->default('PENDING');        // PENDING|ACKNOWLEDGED|RESOLVED|CANCELLED
            $table->timestamp('escalated_at');
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('automatic')->default(false);
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('dedup_key', 191)->nullable()->unique();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'bk_escalations_subject');
            $table->index(['status', 'to_level'], 'bk_escalations_status');
            $table->index(['school_id', 'status'], 'bk_escalations_school');
        });

        Schema::create('bk_retention_policies', function (Blueprint $table): void {
            $table->id();
            // Policy dengan school_id NULL adalah kebijakan bawaan lintas unit.
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('entity_type', 24);                       // CASE|NOTE|DOCUMENT|CONSENT|AUDIT|ASSESSMENT|FEEDBACK|CONFERENCE|EXTERNAL_REFERRAL|DEVELOPMENT_PLAN|RISK_INDICATOR
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('retention_days');
            $table->unsignedInteger('archive_after_days')->nullable();
            $table->boolean('requires_approval_for_disposal')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('legal_basis', 191)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'entity_type', 'version'], 'bk_retention_version');
            $table->index(['entity_type', 'is_active'], 'bk_retention_entity');
        });

        Schema::create('bk_retention_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('bk_retention_policies')->nullOnDelete();
            $table->string('entity_type', 24);
            $table->unsignedBigInteger('entity_id');
            // Active → Archived → Retention Expired → Approved Disposal.
            $table->string('action', 32);                            // ARCHIVED|DISPOSAL_REQUESTED|DISPOSAL_APPROVED|DISPOSAL_REJECTED|DISPOSED|RESTORED|EXPORTED
            $table->string('status', 20)->default('COMPLETED');      // PENDING|COMPLETED|REJECTED|FAILED
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('performed_at');
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'bk_retention_actions_entity');
            $table->index(['action', 'performed_at'], 'bk_retention_actions_action');
            $table->index(['school_id', 'action'], 'bk_retention_actions_school');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_retention_actions');
        Schema::dropIfExists('bk_retention_policies');
        Schema::dropIfExists('bk_escalations');
        Schema::dropIfExists('bk_sla_events');
        Schema::dropIfExists('bk_sla_policies');
        Schema::dropIfExists('bk_feedback');
    }
};
