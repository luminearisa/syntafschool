<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menutup celah skema Fase 2: kolom `deleted_at` yang dipakai model tetapi belum
 * ada di tabelnya.
 *
 * Lima model BK memakai `SoftDeletes`, sehingga setiap kuerinya menambahkan
 * `where deleted_at is null`. Tanpa kolomnya, kueri gagal dengan
 * "no such column: ... deleted_at" — persis yang membuat uji rencana
 * pengembangan gagal. Soft delete juga sejalan dengan kebijakan modul ini:
 * rekaman BK (termasuk jejak audit) tidak dihapus permanen.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tabel = ['bk_audit_logs', 'bk_case_events', 'bk_case_tags', 'bk_development_reviews', 'bk_notification_logs'];

    public function up(): void
    {
        // Jejak audit menyimpan keputusan "diberikan/ditolak" di dalam kolom JSON
        // `context`, sehingga tidak dapat disaring sebagai kolom: pertanyaan
        // "siapa saja yang ditolak bulan ini?" harus membaca seluruh baris.
        // Kolom tersendiri membuat penolakan dapat dihitung dan diaudit.
        if (Schema::hasTable('bk_audit_logs') && ! Schema::hasColumn('bk_audit_logs', 'granted')) {
            Schema::table('bk_audit_logs', function (Blueprint $table): void {
                $table->boolean('granted')->nullable()->after('access_reason');
                $table->index(['granted', 'action'], 'bk_audit_granted_action');
            });
        }

        foreach ($this->tabel as $nama) {
            if (! Schema::hasTable($nama) || Schema::hasColumn($nama, 'deleted_at')) {
                continue;
            }

            Schema::table($nama, function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bk_audit_logs') && Schema::hasColumn('bk_audit_logs', 'granted')) {
            Schema::table('bk_audit_logs', function (Blueprint $table): void {
                $table->dropIndex('bk_audit_granted_action');
                $table->dropColumn('granted');
            });
        }

        foreach ($this->tabel as $nama) {
            if (! Schema::hasTable($nama) || ! Schema::hasColumn($nama, 'deleted_at')) {
                continue;
            }

            Schema::table($nama, function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
