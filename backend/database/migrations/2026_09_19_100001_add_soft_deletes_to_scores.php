<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A7 — Skor siswa sekarang dihapus soft delete.
 *
 * `score_revisions.score_id` menempel ke `scores` dengan FK `cascadeOnDelete`,
 * sehingga penghapusan keras satu catatan nilai ikut menghapus jejak auditnya —
 * justru peristiwa yang paling perlu direkam. Dengan `deleted_at`, barisnya
 * berhenti muncul di daftar nilai tapi jejak revisinya tetap utuh.
 *
 * Konsekuensinya ditangani `ScoreRecorder::put()`: baris yang sudah dihapus
 * dipulihkan saat nilainya dimasukkan lagi, karena indeks unik
 * `(assessment_id, student_id)` tetap menempati baris tersebut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->softDeletes()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
