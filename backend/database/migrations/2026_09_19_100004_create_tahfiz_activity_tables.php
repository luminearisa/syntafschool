<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahfiz T3 — Aktivitas harian, evaluasi, dan pelaporan.
 *
 * Bagian terakhir skema modul Tahfiz. Berisi seluruh catatan aktivitas
 * (setoran + kesalahan, murojaah, tilawah, tahsin, tasmi', mutabaah), ujian,
 * sertifikat, intervention, notifikasi, dan rollup progress.
 *
 * Catatan desain:
 * - `tahfiz_memorization_mistakes` menyimpan detail kesalahan per tipe, agar
 *   analitik bisa menemukan pola (bukan hanya menyimpan skor akhir).
 * - `tahfiz_participant_progress` adalah ringkasan per (peserta, surah) yang
 *   dihitung ulang oleh progress engine setiap ada setoran diterima. Status
 *   mutqin berbeda dari sekadar "pernah setor": jumlah hafalan ≠ jumlah
 *   hafalan mutqin.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= SETORAN HAFALAN =================
        // Pencatatan inti modul: peserta menyetorkan hafalan kepada guru.
        Schema::create('tahfiz_memorization_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->nullable()->constrained('tahfiz_enrollments')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->foreignId('halaqah_id')->nullable()->constrained('tahfiz_halaqahs')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('tahfiz_sessions')->nullOnDelete();
            $table->date('date');
            $table->enum('type', ['hafalan_baru', 'ziyadah', 'setoran', 'tasmi']);
            $table->foreignId('surah_id')->constrained('quran_surahs')->cascadeOnDelete();
            $table->unsignedSmallInteger('ayah_start');
            $table->unsignedSmallInteger('ayah_end');
            $table->unsignedTinyInteger('juz')->nullable();
            $table->unsignedSmallInteger('page_start')->nullable();
            $table->unsignedSmallInteger('page_end')->nullable();
            $table->unsignedSmallInteger('line_count')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            // Penilaian multi-aspek.
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedTinyInteger('fluency')->nullable(); // 1..5
            $table->unsignedTinyInteger('tajwid')->nullable();
            $table->unsignedTinyInteger('makhraj')->nullable();
            $table->unsignedSmallInteger('mistakes_count')->default(0);
            // Status penilaian: belum ditinjau → diterima / revisi / diulang / ditolak.
            $table->enum('status', [
                'submitted', 'reviewed', 'accepted', 'accepted_with_revision', 'repeated', 'rejected',
            ])->default('submitted');
            $table->date('revision_due_date')->nullable();
            $table->text('teacher_note')->nullable();
            $table->text('participant_note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['participant_id', 'date']);
            $table->index(['halaqah_id', 'date']);
            $table->index('surah_id');
        });

        // Detail kesalahan setoran. Bukan hanya skor: setiap kesalahan diberi
        // tipe, jumlah, ayat, dan catatan agar bisa dianalisis pola kesalahannya.
        Schema::create('tahfiz_memorization_mistakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memorization_record_id')->constrained('tahfiz_memorization_records')->cascadeOnDelete();
            $table->foreignId('mistake_type_id')->nullable()->constrained('tahfiz_mistake_types')->nullOnDelete();
            $table->string('type_label', 100)->nullable(); // cadangan bila tipe belum terdaftar
            $table->unsignedSmallInteger('ayah')->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('memorization_record_id');
            $table->index('mistake_type_id');
        });

        // ================= MUROJAAH =================
        // Aktivitas tersendiri dari setoran: mengulang hafalan lama.
        Schema::create('tahfiz_revision_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->nullable()->constrained('tahfiz_enrollments')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->foreignId('halaqah_id')->nullable()->constrained('tahfiz_halaqahs')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('tahfiz_sessions')->nullOnDelete();
            $table->date('date');
            $table->foreignId('surah_id')->nullable()->constrained('quran_surahs')->nullOnDelete();
            $table->unsignedSmallInteger('ayah_start')->nullable();
            $table->unsignedSmallInteger('ayah_end')->nullable();
            $table->unsignedTinyInteger('juz')->nullable();
            $table->unsignedSmallInteger('page_start')->nullable();
            $table->unsignedSmallInteger('page_end')->nullable();
            $table->enum('method', ['individual', 'paired', 'group', 'tasmi', 'teacher_listening'])->default('individual');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedSmallInteger('mistakes_count')->default(0);
            $table->text('teacher_note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['participant_id', 'date']);
        });

        // ================= TILAWAH =================
        // Pencatatan membaca Al-Quran (bukan hafalan).
        Schema::create('tahfiz_tilawah_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->nullable()->constrained('tahfiz_enrollments')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('surah_id')->nullable()->constrained('quran_surahs')->nullOnDelete();
            $table->unsignedSmallInteger('ayah_start')->nullable();
            $table->unsignedSmallInteger('ayah_end')->nullable();
            $table->unsignedTinyInteger('juz')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedTinyInteger('quality')->nullable(); // 1..5
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['participant_id', 'date']);
        });

        // ================= TAHSIN =================
        // Penilaian perbaikan bacaan memakai rubrik, bukan sekadar skor.
        Schema::create('tahfiz_tahsin_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->nullable()->constrained('tahfiz_enrollments')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->foreignId('halaqah_id')->nullable()->constrained('tahfiz_halaqahs')->nullOnDelete();
            $table->date('date');
            $table->foreignId('rubric_id')->nullable()->constrained('tahfiz_rubrics')->nullOnDelete();
            $table->foreignId('instructor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('surah_id')->nullable()->constrained('quran_surahs')->nullOnDelete();
            $table->unsignedSmallInteger('ayah_start')->nullable();
            $table->unsignedSmallInteger('ayah_end')->nullable();
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['participant_id', 'date']);
        });

        Schema::create('tahfiz_tahsin_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahsin_record_id')->constrained('tahfiz_tahsin_records')->cascadeOnDelete();
            $table->foreignId('rubric_item_id')->nullable()->constrained('tahfiz_rubric_items')->nullOnDelete();
            $table->string('criterion', 100);
            $table->decimal('score', 5, 2);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('tahsin_record_id');
        });

        // ================= TASMI' =================
        // Setoran hafalan dalam jumlah besar (1/5/10/30 juz) di hadapan penguji.
        Schema::create('tahfiz_tasmi_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->nullable()->constrained('tahfiz_enrollments')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('juz_from');
            $table->unsignedTinyInteger('juz_to');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('mistakes_count')->default(0);
            $table->decimal('score', 5, 2)->nullable();
            $table->foreignId('examiner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('result', ['lulus', 'lulus_dengan_catatan', 'ulang'])->default('lulus');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['participant_id', 'date']);
        });

        // ================= MUTABAAH HARIAN =================
        // Item mutabaah diambil dari `tahfiz_mutabaah_items`, tidak ditulis
        // keras di kode, agar admin dapat menambah/mengubah item.
        Schema::create('tahfiz_mutabaah', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->date('date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['participant_id', 'date'], 'tm_participant_date_unique');
        });

        Schema::create('tahfiz_mutabaah_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mutabaah_id')->constrained('tahfiz_mutabaah')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('tahfiz_mutabaah_items')->nullOnDelete();
            $table->string('item_label', 100)->nullable(); // cadangan bila item diubah
            $table->boolean('done')->default(false);
            $table->decimal('value', 8, 2)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('mutabaah_id');
        });

        // ================= UJIAN =================
        Schema::create('tahfiz_exams', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->foreignId('program_id')->nullable()->constrained('tahfiz_programs')->nullOnDelete();
            $table->foreignId('halaqah_id')->nullable()->constrained('tahfiz_halaqahs')->nullOnDelete();
            $table->enum('type', [
                'ujian_hafalan', 'sambung_ayat', 'acak_ayat', 'tasmi', 'ujian_tahsin', 'kenaikan_level',
            ]);
            $table->foreignId('rubric_id')->nullable()->constrained('tahfiz_rubrics')->nullOnDelete();
            $table->date('exam_date');
            $table->time('start_time')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->foreignId('examiner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('tahfiz_levels')->nullOnDelete(); // kenaikan level
            $table->decimal('max_score', 5, 2)->default(100);
            $table->decimal('passing_score', 5, 2)->default(75);
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'ongoing', 'done', 'cancelled'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['exam_date', 'status']);
        });

        Schema::create('tahfiz_exam_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('tahfiz_exams')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('tahfiz_enrollments')->nullOnDelete();
            $table->enum('status', ['terdaftar', 'hadir', 'tidak_hadir', 'menunggu_nilai', 'dinilai'])->default('terdaftar');
            $table->decimal('score', 5, 2)->nullable();
            $table->string('grade', 5)->nullable();
            $table->enum('result', ['lulus', 'tidak_lulus', 'remedial'])->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'participant_id'], 'tep_participant_unique');
            $table->index('participant_id');
        });

        // Butir ujian: ayat awal untuk ujian sambung/ayat acak, beserta
        // kelanjutan yang diharapkan dan jawaban peserta.
        Schema::create('tahfiz_exam_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_participant_id')->constrained('tahfiz_exam_participants')->cascadeOnDelete();
            $table->enum('item_type', ['sambung_ayat', 'acak_ayat', 'hafalan', 'tahsin']);
            $table->foreignId('surah_id')->constrained('quran_surahs')->cascadeOnDelete();
            $table->unsignedSmallInteger('ayah_start'); // ayat yang dibacakan/ditunjuk
            $table->unsignedSmallInteger('expected_ayah_end')->nullable(); // kelanjutan yang diharapkan
            $table->unsignedSmallInteger('actual_ayah_end')->nullable(); // yang disebutkan peserta
            $table->decimal('score', 5, 2)->nullable();
            $table->json('errors')->nullable(); // jenis kesalahan beserta catatannya
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('exam_participant_id');
        });

        // ================= PROGRESS =================
        // Ringkasan per (peserta, surah) yang dihitung ulang oleh progress
        // engine. Status membedakan "pernah setor" dengan "mutqin" —
        // jumlah hafalan tidak sama dengan jumlah hafalan mutqin.
        Schema::create('tahfiz_participant_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->foreignId('surah_id')->constrained('quran_surahs')->cascadeOnDelete();
            $table->unsignedTinyInteger('juz')->nullable();
            $table->unsignedSmallInteger('ayahs_memorized')->default(0);
            $table->unsignedSmallInteger('pages_memorized')->default(0);
            $table->unsignedSmallInteger('ayahs_mutqin')->default(0);
            $table->unsignedSmallInteger('pages_mutqin')->default(0);
            // hafal = pernah setor; lancar = sedikit kesalahan;
            // mutqin = stabil dan dapat dipanggil kembali; belum_mutqin = perlu murojaah.
            $table->enum('status', ['belum', 'hafal', 'lancar', 'mutqin', 'belum_mutqin'])->default('belum');
            $table->decimal('avg_score', 5, 2)->nullable();
            $table->unsignedInteger('setoran_count')->default(0);
            $table->unsignedInteger('murojaah_count')->default(0);
            $table->date('last_setor_date')->nullable();
            $table->date('last_murojaah_date')->nullable();
            $table->timestamps();

            $table->unique(['participant_id', 'surah_id'], 'tpp_participant_surah_unique');
            $table->index(['participant_id', 'juz']);
        });

        // ================= SERTIFIKAT =================
        Schema::create('tahfiz_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('certificate_number', 60)->unique();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('tahfiz_enrollments')->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('tahfiz_programs')->nullOnDelete();
            $table->enum('achievement_type', [
                'selesai_program', 'hafal_1_juz', 'hafal_5_juz', 'hafal_10_juz',
                'hafal_30_juz', 'lulus_tasmi', 'lulus_tahsin',
            ]);
            $table->unsignedTinyInteger('juz_count')->nullable();
            $table->string('title', 150)->nullable();
            $table->text('description')->nullable();
            $table->date('issue_date');
            $table->foreignId('exam_id')->nullable()->constrained('tahfiz_exams')->nullOnDelete();
            $table->foreignId('examiner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('issuer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signature_name', 150)->nullable();
            $table->string('qr_token', 64)->unique(); // verifikasi publik tanpa login
            $table->enum('status', ['issued', 'revoked'])->default('issued');
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['participant_id', 'status']);
        });

        // ================= INTERVENTION =================
        // Peserta tertinggal terdeteksi dari target; guru/musyrif menindaklanjuti.
        Schema::create('tahfiz_interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('tahfiz_enrollments')->nullOnDelete();
            $table->foreignId('halaqah_id')->nullable()->constrained('tahfiz_halaqahs')->nullOnDelete();
            $table->enum('type', [
                'tambahan_murojaah', 'pengurangan_target', 'sesi_khusus',
                'bimbingan_individual', 'remedial_hafalan', 'naik_level', 'turun_level',
            ]);
            $table->text('reason');
            $table->text('action')->nullable();
            $table->enum('status', ['proposed', 'in_progress', 'done', 'cancelled'])->default('proposed');
            $table->date('due_date')->nullable();
            $table->date('completed_at')->nullable();
            $table->text('result')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['participant_id', 'status']);
        });

        // ================= NOTIFIKASI =================
        // Notifikasi per peran: peserta, guru, musyrif, wali, admin.
        Schema::create('tahfiz_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('tahfiz_participants')->nullOnDelete();
            $table->enum('audience', ['participant', 'instructor', 'musyrif', 'parent', 'admin']);
            $table->string('type', 50); // setoran_reviewed, target_overdue, exam_scheduled …
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['audience', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tahfiz_notifications');
        Schema::dropIfExists('tahfiz_interventions');
        Schema::dropIfExists('tahfiz_certificates');
        Schema::dropIfExists('tahfiz_participant_progress');
        Schema::dropIfExists('tahfiz_exam_items');
        Schema::dropIfExists('tahfiz_exam_participants');
        Schema::dropIfExists('tahfiz_exams');
        Schema::dropIfExists('tahfiz_mutabaah_entries');
        Schema::dropIfExists('tahfiz_mutabaah');
        Schema::dropIfExists('tahfiz_tasmi_records');
        Schema::dropIfExists('tahfiz_tahsin_scores');
        Schema::dropIfExists('tahfiz_tahsin_records');
        Schema::dropIfExists('tahfiz_tilawah_records');
        Schema::dropIfExists('tahfiz_revision_records');
        Schema::dropIfExists('tahfiz_memorization_mistakes');
        Schema::dropIfExists('tahfiz_memorization_records');
    }
};
