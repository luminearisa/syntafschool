<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan keputusan koreksi presensi.
 *
 * Sebelumnya alasan penolakan ditempel ke `reason` (alasan pengajuan asli),
 * sehingga dua hal berbeda bercampur di satu kolom. Kolom terpisah ini menjaga
 * alasan pengajuan tetap utuh dan mencatat keputusan (setuju/tolak) berdampingan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->string('decision_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropColumn('decision_note');
        });
    }
};
