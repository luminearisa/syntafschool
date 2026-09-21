<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penutup tiga celah hasil audit modul PPDB.
 *
 * - Bobot tahap seleksi dibuat data, bukan angka di dalam kode, sehingga skor
 *   akhir dapat dihitung dari kombinasi akademik/tahfidz/wawancara sesuai
 *   kebijakan sekolah.
 * - Masa sanggah disediakan sebagai jendela waktu per periode beserta pengajuan
 *   keberatan orang tua, agar hasil seleksi tidak menjadi jalan buntu.
 * - Penetapan `passed` di atas kuota butuh alasan eksplisit yang tersimpan,
 *   supaya pelanggaran kuota selalu terlihat di jejak audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppdb_selection_stages', function (Blueprint $table): void {
            $table->decimal('weight', 6, 2)->default(100);
        });

        Schema::table('ppdb_periods', function (Blueprint $table): void {
            $table->date('appeal_start')->nullable();
            $table->date('appeal_end')->nullable();
        });

        Schema::table('ppdb_registrations', function (Blueprint $table): void {
            $table->text('quota_override_reason')->nullable();
        });

        Schema::create('ppdb_registration_appeals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('registration_id')->constrained('ppdb_registrations')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('ppdb_periods')->cascadeOnDelete();
            $table->foreignId('parent_account_id')->constrained('ppdb_parent_accounts')->cascadeOnDelete();
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['registration_id', 'status']);
            $table->index(['period_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppdb_registration_appeals');

        Schema::table('ppdb_registrations', function (Blueprint $table): void {
            $table->dropColumn('quota_override_reason');
        });

        Schema::table('ppdb_periods', function (Blueprint $table): void {
            $table->dropColumn(['appeal_start', 'appeal_end']);
        });

        Schema::table('ppdb_selection_stages', function (Blueprint $table): void {
            $table->dropColumn('weight');
        });
    }
};
