<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan `school_id` pada tabel BK yang belum memilikinya.
 *
 * Empat puluh satu model BK memakai trait `BelongsToSchool`, yang **mengisi
 * school_id saat baris dibuat** dan menyaring setiap kueri menurut unit aktif.
 * Namun dua puluh tiga tabelnya belum pernah punya kolom itu, sehingga begitu
 * penyaringan unit menyala, pembuatan rekaman gagal dengan
 * "table ... has no column named school_id" — bukan kegagalan yang dapat
 * dijelaskan kepada pengguna, dan hanya muncul pada instalasi multi-unit.
 *
 * Kolomnya `nullable`: instalasi satu sekolah tidak pernah mengisinya, dan
 * perilaku lama harus tetap utuh. Penyaringan unit hanya bekerja bila unit
 * aktif diketahui (lihat `BelongsToSchool`).
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tabel = ['bk_appointments', 'bk_assessments', 'bk_case_conference_actions', 'bk_case_conference_participants', 'bk_case_documents', 'bk_case_events', 'bk_case_notes', 'bk_cases', 'bk_consents', 'bk_counseling_plans', 'bk_counseling_sessions', 'bk_development_activities', 'bk_development_goals', 'bk_development_reviews', 'bk_follow_ups', 'bk_group_program_attendance', 'bk_group_program_members', 'bk_group_program_sessions', 'bk_interventions', 'bk_parent_meetings', 'bk_referrals', 'bk_screenings', 'bk_support_requests'];

    public function up(): void
    {
        foreach ($this->tabel as $nama) {
            if (! Schema::hasTable($nama) || Schema::hasColumn($nama, 'school_id')) {
                continue;
            }

            Schema::table($nama, function (Blueprint $table): void {
                $table->foreignId('school_id')->nullable()->after('id')->constrained('schools')->nullOnDelete();
                $table->index('school_id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tabel as $nama) {
            if (! Schema::hasTable($nama) || ! Schema::hasColumn($nama, 'school_id')) {
                continue;
            }

            Schema::table($nama, function (Blueprint $table): void {
                $table->dropForeign(['school_id']);
                $table->dropIndex(['school_id']);
                $table->dropColumn('school_id');
            });
        }
    }
};
