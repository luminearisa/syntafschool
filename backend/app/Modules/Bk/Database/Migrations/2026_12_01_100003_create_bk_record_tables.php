<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul BK — tabel rekaman: pertemuan orang tua, dokumen kasus, catatan kasus,
 * persetujuan orang tua, jejak audit, dan log notifikasi.
 *
 * Dua tabel di sini adalah penegak akuntabilitas modul:
 *
 * - `bk_audit_logs` mencatat **setiap akses** ke data BK, termasuk pembacaan
 *   data rahasia (aksi `VIEW_CONFIDENTIAL`), lengkap dengan pelaku, peran,
 *   alasan akses, dan konteks permintaan.
 * - `bk_notification_logs` menyimpan **ringkasan non-sensitif** dari pesan yang
 *   dikirim; isi catatan konseling tidak pernah masuk ke sini maupun ke pesan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Persetujuan orang tua ----------
        Schema::create('bk_consents', function (Blueprint $table): void {
            $table->id();
            $table->string('consent_number', 32)->unique();         // BK-CS-2026-000001
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('bk_referrals')->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 32)->default('COUNSELING');     // COUNSELING | SHARING | REFERRAL | ASSESSMENT
            $table->text('purpose')->nullable();
            $table->string('status', 16)->default('PENDING');       // PENDING | APPROVED | DECLINED | EXPIRED
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 191)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('bk_case_documents')->nullOnDelete();
            $table->string('confidentiality_level', 24)->default('RESTRICTED');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'status'], 'bk_consents_student');
            $table->index(['case_id', 'status'], 'bk_consents_case');
        });

        // FK balik dari referral ke consent (keduanya kini sudah ada).
        Schema::table('bk_referrals', function (Blueprint $table): void {
            $table->foreign('consent_id')->references('id')->on('bk_consents')->nullOnDelete();
        });

        // ---------- Pertemuan orang tua ----------
        Schema::create('bk_parent_meetings', function (Blueprint $table): void {
            $table->id();
            $table->string('meeting_number', 32)->unique();         // BK-PM-2026-000001
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('counselor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('mode', 16)->default('IN_PERSON');
            $table->string('location', 120)->nullable();
            $table->text('agenda')->nullable();
            $table->text('discussion')->nullable();
            $table->text('agreed_actions')->nullable();
            $table->string('status', 16)->default('REQUESTED');     // ParentMeetingStatus
            $table->timestamp('minutes_recorded_at')->nullable();
            $table->foreignId('minutes_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_meeting_on')->nullable();
            $table->string('confidentiality_level', 24)->default('RESTRICTED');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['case_id', 'status']);
            $table->index('scheduled_at');
        });

        Schema::create('bk_parent_meeting_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained('bk_parent_meetings')->cascadeOnDelete();
            $table->string('role', 24);                             // CaseParticipantRole
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('contact', 80)->nullable();
            $table->string('attendance_status', 16)->default('UNKNOWN');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['meeting_id', 'role']);
        });

        Schema::create('bk_parent_meeting_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained('bk_parent_meetings')->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->text('description');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_name', 120)->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 16)->default('PENDING');       // FollowUpStatus
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('follow_up_id')->nullable()->constrained('bk_follow_ups')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['meeting_id', 'status']);
            $table->index(['case_id', 'status']);
        });

        // ---------- Dokumen kasus (isi berkas di DocumentProviderInterface) ----------
        Schema::create('bk_case_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->cascadeOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('bk_referrals')->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained('bk_parent_meetings')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('document_type', 32);                    // DocumentType
            $table->string('title', 160);
            $table->string('provider', 40)->default('local');
            $table->string('provider_reference', 191);              // id berkas pada penyedia dokumen
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->date('document_date')->nullable();
            $table->string('visibility', 24)->default('COUNSELOR_ONLY'); // ConfidentialityLevel
            $table->boolean('is_sensitive')->default(false);
            $table->json('shared_with')->nullable();                // daftar peran/pengguna yang boleh membuka
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['case_id', 'document_type'], 'bk_documents_case');
            $table->index('visibility');
        });

        // ---------- Catatan kasus (termasuk catatan sensitif konselor) ----------
        Schema::create('bk_case_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('bk_counseling_sessions')->nullOnDelete();
            $table->string('note_type', 24)->default('GENERAL');    // NoteType
            $table->text('body');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_role', 40)->nullable();
            $table->string('confidentiality_level', 24)->default('COUNSELOR_ONLY');
            $table->boolean('is_sensitive')->default(false);
            $table->unsignedBigInteger('amended_from_id')->nullable();
            $table->json('visible_to')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['case_id', 'note_type'], 'bk_notes_case');
            $table->index(['author_id', 'occurred_at'], 'bk_notes_author');
        });

        // ---------- Jejak audit: append-only ----------
        Schema::create('bk_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 24);                           // AuditAction
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('bk_referrals')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 40)->nullable();
            $table->string('confidentiality_level', 24)->nullable();
            $table->string('access_reason', 191)->nullable();
            $table->json('changes')->nullable();                     // {field: {from, to}} — tanpa isi catatan
            $table->json('context')->nullable();                     // ip, user agent, request id
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['case_id', 'occurred_at'], 'bk_audit_case');
            $table->index(['student_id', 'occurred_at'], 'bk_audit_student');
            $table->index(['actor_id', 'occurred_at'], 'bk_audit_actor');
            $table->index(['subject_type', 'subject_id'], 'bk_audit_subject');
            $table->index('action');
        });

        // ---------- Log notifikasi (ringkasan non-sensitif) ----------
        Schema::create('bk_notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 32);                            // NotificationEvent
            $table->foreignId('notifiable_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_role', 40)->nullable();
            $table->string('channel', 24)->default('database');
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('status', 16)->default('SENT');          // SENT | SKIPPED | FAILED
            $table->string('summary', 191);                          // ringkasan aman, tanpa isi catatan
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 191)->nullable();
            $table->timestamps();

            $table->index(['event', 'created_at'], 'bk_notify_event');
            $table->index(['notifiable_user_id', 'created_at'], 'bk_notify_user');
        });
    }

    public function down(): void
    {
        Schema::table('bk_referrals', function (Blueprint $table): void {
            $table->dropForeign(['consent_id']);
        });

        foreach ([
            'bk_notification_logs', 'bk_audit_logs', 'bk_case_notes', 'bk_case_documents',
            'bk_parent_meeting_actions', 'bk_parent_meeting_participants', 'bk_parent_meetings',
            'bk_consents',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
