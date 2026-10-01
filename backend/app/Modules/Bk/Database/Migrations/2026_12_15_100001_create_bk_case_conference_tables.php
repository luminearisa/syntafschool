<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konferensi kasus (case conference) — kolaborasi lintas pihak.
 *
 * Konferensi adalah ruang bertukar informasi operasional. Peserta dari luar tim BK
 * tidak otomatis memperoleh akses catatan konseling: kewenangannya ditentukan per
 * peserta (`can_view_confidential` + `max_confidentiality`).
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
        Schema::create('bk_case_conferences', function (Blueprint $table): void {
            $table->id();
            $table->string('conference_number', 32)->unique();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('purpose')->nullable();
            $table->string('status', 20)->default('DRAFT');         // DRAFT|SCHEDULED|IN_PROGRESS|COMPLETED|CANCELLED|RESCHEDULED
            $table->string('confidentiality_level', 24)->default('RESTRICTED');
            $table->string('mode', 16)->default('IN_PERSON');       // IN_PERSON|ONLINE|HYBRID|PHONE
            $table->string('location', 160)->nullable();
            $table->timestamp('scheduled_start')->nullable();
            $table->timestamp('scheduled_end')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('agenda')->nullable();
            $table->text('discussion')->nullable();
            $table->text('decision')->nullable();
            $table->string('decision_outcome', 32)->nullable();     // NO_ACTION|MONITOR|INTERVENTION|REFERRAL|EXTERNAL_REFERRAL|ESCALATE|PARENT_PARTNERSHIP
            $table->boolean('follow_up_required')->default(false);
            $table->text('follow_up_summary')->nullable();
            $table->foreignId('facilitator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('minutes_document_id')->nullable()->constrained('bk_case_documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['case_id', 'status'], 'bk_conferences_case_status');
            $table->index(['student_id', 'scheduled_start'], 'bk_conferences_student_date');
            $table->index(['school_id', 'status'], 'bk_conferences_school_status');
        });

        Schema::create('bk_case_conference_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conference_id')->constrained('bk_case_conferences')->cascadeOnDelete();
            // Peran peserta menentukan apa yang boleh dibuka: peserta tidak otomatis
            // memperoleh catatan konseling kasusnya.
            $table->string('participant_type', 32);                 // COUNSELOR|BK_COORDINATOR|HOMEROOM_TEACHER|TEACHER|BOARDING_STAFF|CLINIC_STAFF|MANAGEMENT|PARENT|STUDENT|EXTERNAL_PROVIDER|OTHER
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('external_provider_id')->nullable()->constrained('bk_referral_providers')->nullOnDelete();
            $table->string('external_name', 160)->nullable();
            $table->string('external_organization', 160)->nullable();
            $table->string('external_email', 160)->nullable();
            $table->string('role_label', 80)->nullable();
            $table->string('invitation_status', 20)->default('PENDING');   // PENDING|INVITED|CONFIRMED|DECLINED|ATTENDED|ABSENT
            $table->boolean('is_required')->default(false);
            $table->boolean('can_view_case')->default(true);
            $table->boolean('can_view_confidential')->default(false);
            $table->string('max_confidentiality', 24)->default('STAFF_ONLY');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('attended_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conference_id', 'participant_type'], 'bk_conf_participants_type');
            $table->index(['user_id', 'invitation_status'], 'bk_conf_participants_user');
        });

        Schema::create('bk_case_conference_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conference_id')->constrained('bk_case_conferences')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_role', 32)->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('PENDING');       // PENDING|IN_PROGRESS|COMPLETED|CANCELLED|OVERDUE
            $table->string('priority', 16)->default('NORMAL');
            $table->timestamp('completed_at')->nullable();
            $table->text('outcome')->nullable();
            $table->foreignId('follow_up_id')->nullable()->constrained('bk_follow_ups')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('bk_case_documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conference_id', 'status'], 'bk_conf_actions_conference');
            $table->index(['responsible_user_id', 'due_date'], 'bk_conf_actions_owner_due');
            $table->index(['status', 'due_date'], 'bk_conf_actions_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_case_conference_actions');
        Schema::dropIfExists('bk_case_conference_participants');
        Schema::dropIfExists('bk_case_conferences');
    }
};
