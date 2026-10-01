<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perluasan aditif tabel Phase 1: isolasi unit + persetujuan granular.
 *
 * Tidak ada kolom yang dihapus atau diganti nama, sehingga kode Phase 1 tetap
 * berjalan apa adanya; yang ditambahkan hanyalah kolom nullable dan penormalkan
 * istilah status persetujuan.
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
        // Isolasi unit sekolah untuk tabel Phase 1. Kosong = perilaku lama.
        $phaseOne = [
            'bk_referrals', 'bk_cases', 'bk_screenings', 'bk_case_events', 'bk_case_assignments',
            'bk_case_participants', 'bk_case_notes', 'bk_case_documents', 'bk_counseling_sessions',
            'bk_counseling_plans', 'bk_goals', 'bk_interventions', 'bk_follow_ups', 'bk_consents',
            'bk_parent_meetings', 'bk_assessments', 'bk_appointments', 'bk_audit_logs',
            'bk_notification_logs', 'bk_support_requests',
        ];

        foreach ($phaseOne as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'school_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('school_id')->nullable()->after('id')->constrained('schools')->nullOnDelete();
            });
        }

        /*
         | Persetujuan menjadi granular: satu baris dapat merujuk pada siswa ATAU
         | wali (`subject_type`/`subject_id`), dan menyimpan jejak pemberian serta
         | pencabutan izin — bukan hanya keputusan awal.
         */
        if (! Schema::hasColumn('bk_consents', 'subject_type')) {
            Schema::table('bk_consents', function (Blueprint $table): void {
                $table->string('subject_type', 24)->default('STUDENT')->after('student_id');
                $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
                $table->foreignId('granted_by')->nullable()->after('decided_by')->constrained('users')->nullOnDelete();
                $table->timestamp('granted_at')->nullable()->after('granted_by');
                $table->foreignId('revoked_by')->nullable()->after('granted_at')->constrained('users')->nullOnDelete();
                $table->timestamp('revoked_at')->nullable()->after('revoked_by');
                $table->string('revocation_reason', 191)->nullable()->after('revoked_at');
                $table->boolean('is_standing')->default(false)->after('revocation_reason');
                $table->index(['subject_type', 'subject_id'], 'bk_consents_subject');
                $table->index(['status', 'expires_at'], 'bk_consents_expiry');
            });
        }

        /*
         | Penyelarasan istilah status. Phase 1 memakai PENDING/APPROVED; Phase 2
         | memakai REQUESTED/GRANTED agar sejalan dengan istilah baku modul
         | (REQUESTED, GRANTED, DECLINED, EXPIRED, REVOKED). Data lama dinormalkan
         | di sini supaya tidak ada baris yang jatuh ke status tak dikenal, dan
         | enum tetap menerima ejaan lama sebagai alias baca.
         */
        DB::table('bk_consents')->where('status', 'PENDING')->update(['status' => 'REQUESTED']);
        DB::table('bk_consents')->where('status', 'APPROVED')->update(['status' => 'GRANTED']);
        DB::table('bk_consents')->whereNotNull('decided_at')->whereNull('granted_at')
            ->where('status', 'GRANTED')->update(['granted_at' => DB::raw('decided_at'), 'granted_by' => DB::raw('decided_by')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('bk_consents', 'subject_type')) {
            Schema::table('bk_consents', function (Blueprint $table): void {
                $table->dropIndex('bk_consents_subject');
                $table->dropIndex('bk_consents_expiry');
                $table->dropConstrainedForeignId('granted_by');
                $table->dropConstrainedForeignId('revoked_by');
                $table->dropColumn([
                    'subject_type', 'subject_id', 'granted_at', 'revoked_at', 'revocation_reason', 'is_standing',
                ]);
            });
        }

        foreach ([
            'bk_referrals', 'bk_cases', 'bk_screenings', 'bk_case_events', 'bk_case_assignments',
            'bk_case_participants', 'bk_case_notes', 'bk_case_documents', 'bk_counseling_sessions',
            'bk_counseling_plans', 'bk_goals', 'bk_interventions', 'bk_follow_ups', 'bk_consents',
            'bk_parent_meetings', 'bk_assessments', 'bk_appointments', 'bk_audit_logs',
            'bk_notification_logs', 'bk_support_requests',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'school_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropConstrainedForeignId('school_id');
                });
            }
        }
    }
};
