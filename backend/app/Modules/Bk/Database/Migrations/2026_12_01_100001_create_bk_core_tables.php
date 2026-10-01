<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Bimbingan & Konseling (BK) — tabel inti: konselor, ketersediaan,
 * label kasus, referral, kasus, penugasan, pihak terlibat, dan timeline.
 *
 * Seluruh tabel berprefix `bk_` dan bersifat **tambahan** — tidak ada tabel inti
 * yang diubah. Data siswa/orang tua/akademik/kehadiran/kedisiplinan/asrama/
 * klinik/aktivitas tetap menjadi milik modulnya masing-masing dan hanya dibaca
 * lewat kontrak integrasi; BK hanya menyimpan apa yang memang menjadi sumber
 * kebenarannya: referral, kasus, screening, konseling, asesmen, rencana,
 * intervensi, dan tindak lanjut.
 *
 * Kerahasiaan ditegakkan `BkAccessService` (bukan di skema): kolom
 * `confidentiality_level` menyimpan niat pemilik data, sedangkan keputusan
 * "siapa boleh membuka baris ini" selalu dihitung di server.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Profil konselor (penugasan user → kekhususan & kapasitas) ----------
        Schema::create('bk_counselors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('code', 24)->nullable()->unique();      // LN-BK-001
            $table->string('specialization', 120)->nullable();      // mis. "Remaja & keluarga"
            $table->string('qualification', 160)->nullable();       // latar pendidikan/sertifikasi
            $table->string('level', 24)->default('NONE');      // COUNSELOR | SENIOR | COORDINATOR
            $table->unsignedSmallInteger('max_active_cases')->default(25); // kapasitas pantauan beban kerja
            $table->boolean('accepts_self_referral')->default(true);
            $table->boolean('accepts_walk_in')->default(false);
            $table->string('status', 16)->default('ACTIVE');        // ACTIVE | INACTIVE | SUSPENDED
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'level']);
        });

        // ---------- Ketersediaan konselor (dasar penjadwalan & deteksi bentrok) ----------
        Schema::create('bk_counselor_availability', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('counselor_id')->constrained('bk_counselors')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');             // 1 = Senin … 7 = Minggu
            $table->time('start_time');
            $table->time('end_time');
            $table->string('mode', 16)->default('IN_PERSON');       // IN_PERSON | ONLINE | PHONE | OTHER
            $table->string('location', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['counselor_id', 'day_of_week', 'start_time'], 'bk_availability_unique');
            $table->index(['counselor_id', 'day_of_week', 'is_active'], 'bk_availability_lookup');
        });

        // ---------- Label kasus (mis. "kecemasan ujian", "sering terlambat") ----------
        Schema::create('bk_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('category', 24)->default('OTHER');       // mengikuti ReferralCategory
            $table->string('color', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        // ---------- Referral: pintu masuk utama ----------
        Schema::create('bk_referrals', function (Blueprint $table): void {
            $table->id();
            $table->string('referral_number', 32)->unique();        // BK-R-2026-000001 (immutable)
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('source_type', 24);                      // ReferralSource
            $table->unsignedBigInteger('source_id')->nullable();     // id entitas sumber (bila ada)
            $table->string('source_label', 160)->nullable();         // keterangan sumber non-FK
            $table->string('category', 24)->default('OTHER');
            $table->string('priority', 16)->default('NORMAL');
            $table->text('description');                            // apa yang diamati pelapor
            $table->text('observation')->nullable();
            $table->text('requested_support')->nullable();
            $table->string('confidentiality_level', 24)->default('RESTRICTED');
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('referred_by_role', 40)->nullable();
            $table->timestamp('referred_at');
            $table->string('status', 16)->default('SUBMITTED');      // ReferralStatus
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('screened_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // Referensi ke kasus yang lahir dari referral ini (FK ditulis setelah
            // bk_cases ada, karena hubungannya dua arah).
            $table->unsignedBigInteger('case_id')->nullable();
            $table->boolean('consent_required')->default(false);
            $table->unsignedBigInteger('consent_id')->nullable(); // FK ditambahkan di migrasi rekaman
            $table->string('resolution_note', 191)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority'], 'bk_referrals_queue');
            $table->index(['student_id', 'status'], 'bk_referrals_student');
            $table->index(['source_type', 'referred_at'], 'bk_referrals_source');
        });

        // ---------- Kasus: simpul utama seluruh alur BK ----------
        Schema::create('bk_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_number', 32)->unique();            // BK-C-2026-000001 (immutable)
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('case_type', 32);                        // CaseType
            $table->string('category', 24)->default('OTHER');        // ReferralCategory
            $table->string('title', 160)->nullable();               // ringkasan non-sensitif untuk daftar
            $table->string('priority', 16)->default('NORMAL');
            $table->string('severity', 16)->default('LEVEL_1');     // klasifikasi operasional, bukan diagnosis
            $table->string('confidentiality_level', 24)->default('COUNSELOR_ONLY');
            $table->foreignId('source_referral_id')->nullable()->constrained('bk_referrals')->nullOnDelete();
            $table->foreignId('assigned_counselor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->date('target_review_date')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->date('next_review_date')->nullable();
            $table->string('status', 16)->default('OPEN');          // CaseStatus
            $table->string('escalation_level', 16)->default('NONE'); // EscalationLevel
            $table->timestamp('escalated_at')->nullable();
            $table->string('escalated_to', 160)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedSmallInteger('reopen_count')->default(0);
            $table->string('closure_reason', 32)->nullable();       // ClosureReason
            $table->text('closure_summary')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('risk_flag')->default(false);           // penanda aman untuk widget, bukan isi
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority'], 'bk_cases_queue');
            $table->index(['assigned_counselor_id', 'status'], 'bk_cases_counselor');
            $table->index(['student_id', 'status'], 'bk_cases_student');
            $table->index('target_review_date');
        });

        // Hubungan dua arah referral ↔ kasus (dibuat setelah keduanya ada).
        Schema::table('bk_referrals', function (Blueprint $table): void {
            $table->foreign('case_id')->references('id')->on('bk_cases')->nullOnDelete();
        });

        // ---------- Label pada kasus ----------
        Schema::create('bk_case_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('bk_tags')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['case_id', 'tag_id']);
        });

        // ---------- Riwayat penugasan konselor (bukan hanya kolom di kasus) ----------
        Schema::create('bk_case_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assignment_type', 16)->default('PRIMARY'); // AssignmentType
            $table->timestamp('assigned_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('workload_at_assignment')->nullable(); // beban saat ditugaskan
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'is_active']);
            $table->index(['user_id', 'is_active']);
        });

        // ---------- Pihak yang terlibat & boleh melihat kasus ----------
        Schema::create('bk_case_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->string('role', 24);                             // CaseParticipantRole
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->string('name', 120)->nullable();                // pihak luar tanpa akun
            $table->string('contact', 80)->nullable();
            $table->boolean('can_view_case')->default(false);        // dibagikan sebagian (mis. wali kelas)
            $table->string('max_confidentiality', 24)->nullable();   // batas level yang boleh dibuka
            $table->text('notes')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['case_id', 'role']);
            $table->index(['user_id', 'can_view_case'], 'bk_participants_shared');
        });

        // ---------- Timeline kasus: append-only ----------
        Schema::create('bk_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->cascadeOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('bk_referrals')->cascadeOnDelete();
            $table->string('event_type', 32);                       // CaseEventType
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 40)->nullable();
            $table->string('summary', 191);                          // ringkasan non-sensitif
            $table->string('status_from', 24)->nullable();
            $table->string('status_to', 24)->nullable();
            $table->string('related_type', 120)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->json('payload')->nullable();                     // hanya metadata, bukan isi catatan
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['case_id', 'occurred_at'], 'bk_events_case');
            $table->index(['referral_id', 'occurred_at'], 'bk_events_referral');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::table('bk_referrals', function (Blueprint $table): void {
            $table->dropForeign(['case_id']);
        });

        foreach ([
            'bk_case_events', 'bk_case_participants', 'bk_case_assignments', 'bk_case_tags',
            'bk_cases', 'bk_referrals', 'bk_tags', 'bk_counselor_availability', 'bk_counselors',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
