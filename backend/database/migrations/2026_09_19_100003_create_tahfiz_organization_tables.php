<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahfiz T2 — Peserta, pendaftaran, halaqah, jadwal, dan target.
 *
 * Konsep inti modul ini: peserta (participants) adalah entitas tunggal. Tidak
 * ada tabel terpisah per jenis peserta — `participant_type` membedakan siswa,
 * pegawai, wali, peserta umum, alumni, dan lainnya. Peserta umum menyimpan
 * profilnya langsung di tabel ini karena tidak ada entitas induk.
 *
 * Satu user boleh punya lebih dari satu konteks: seorang wali (guardian) bisa
 * menjadi peserta Tahfiz Dewasa tanpa membuat akun baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= PESERTA =================
        Schema::create('tahfiz_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('participant_type', ['student', 'employee', 'guardian', 'public', 'alumni', 'other']);
            // Tautan ke entitas induk. Hanya satu yang terisi sesuai jenis peserta.
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            // Profil peserta umum / alumni ditulis langsung di sini.
            $table->string('name', 150);
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->date('birth_date')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'inactive', 'blacklisted'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('participant_type');
            $table->index('status');
            $table->index('name');
            // Satu entitas induk hanya boleh muncul sekali sebagai peserta.
            $table->unique(['participant_type', 'student_id'], 'tp_student_unique');
            $table->unique(['participant_type', 'employee_id'], 'tp_employee_unique');
            $table->unique(['participant_type', 'guardian_id'], 'tp_guardian_unique');
        });

        // Atribut pendaftaran peserta: pengalaman menghafal, hafalan terakhir,
        // kemampuan membaca, target, preferensi jadwal. Dipisah dari identitas.
        Schema::create('tahfiz_participant_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->enum('experience_level', ['beginner', 'intermediate', 'advanced'])->nullable();
            $table->string('last_memorized', 150)->nullable(); // "Juz 30 sampai An-Naba"
            $table->enum('reading_ability', ['not_yet', 'learning', 'fluent', 'tahsin_completed'])->nullable();
            $table->string('memorization_target', 150)->nullable();
            $table->string('schedule_preference', 150)->nullable();
            $table->string('education_background', 100)->nullable();
            $table->string('occupation', 100)->nullable();
            $table->string('emergency_contact', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('participant_id');
        });

        // ================= PENDAFTARAN MANDIRI =================
        // Pengajuan peserta umum yang menunggu review admin.
        Schema::create('tahfiz_registration_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->date('birth_date')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('program_id')->constrained('tahfiz_programs')->cascadeOnDelete();
            $table->enum('experience_level', ['beginner', 'intermediate', 'advanced'])->nullable();
            $table->string('last_memorized', 150)->nullable();
            $table->enum('reading_ability', ['not_yet', 'learning', 'fluent', 'tahsin_completed'])->nullable();
            $table->string('memorization_target', 150)->nullable();
            $table->string('schedule_preference', 150)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('participant_id')->nullable()->constrained('tahfiz_participants')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // ================= HALAQAH =================
        // Dideklarasikan sebelum enrollment karena enrollment mereferensinya.
        Schema::create('tahfiz_halaqahs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('tahfiz_programs')->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('tahfiz_levels')->nullOnDelete();
            $table->string('name', 150);
            $table->string('code', 30)->unique();
            $table->unsignedSmallInteger('capacity')->default(15);
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('instructor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('assistant_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('musyrif_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('schedule_summary', 150)->nullable(); // "Senin–Kamis 15.00–16.30"
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['active', 'inactive', 'merged'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['program_id', 'status']);
            $table->index('instructor_id');
        });

        // ================= ENROLLMENT =================
        // Satu peserta pada satu program. Statusnya berjalan:
        // draft → registered → assessment → approved → active → (suspended | completed | withdrawn | rejected)
        Schema::create('tahfiz_enrollments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('tahfiz_programs')->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('tahfiz_levels')->nullOnDelete();
            $table->foreignId('halaqah_id')->nullable()->constrained('tahfiz_halaqahs')->nullOnDelete();
            // Konteks akademik (diisi utamanya untuk peserta siswa).
            $table->foreignId('school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', [
                'draft', 'registered', 'assessment', 'approved', 'active',
                'suspended', 'completed', 'withdrawn', 'rejected',
            ])->default('registered');
            $table->date('enrolled_at')->nullable();
            $table->date('start_date')->nullable();
            $table->date('expected_end_date')->nullable();
            $table->date('completed_at')->nullable();
            $table->string('starting_juz', 50)->nullable();
            $table->string('target_note', 150)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['participant_id', 'status']);
            $table->index(['program_id', 'status']);
            $table->index('halaqah_id');
            // Satu peserta hanya boleh punya satu enrollment per program.
            $table->unique(['participant_id', 'program_id'], 'te_participant_program_unique');
        });

        // Anggota halaqah. Mengaitkan enrollment agar program & peserta konsisten.
        Schema::create('tahfiz_halaqah_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('halaqah_id')->constrained('tahfiz_halaqahs')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('tahfiz_enrollments')->nullOnDelete();
            $table->date('joined_at');
            $table->date('left_at')->nullable();
            $table->enum('status', ['active', 'inactive', 'moved'])->default('active');
            $table->timestamps();

            $table->unique(['halaqah_id', 'participant_id'], 'thm_member_unique');
            $table->index(['halaqah_id', 'status']);
        });

        // ================= PLACEMENT TEST =================
        Schema::create('tahfiz_placement_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('tahfiz_programs')->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('tahfiz_enrollments')->nullOnDelete();
            $table->date('test_date');
            $table->foreignId('examiner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('rubric_id')->nullable()->constrained('tahfiz_rubrics')->nullOnDelete();
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
            // Rekomendasi hasil placement: level, juz mulai, target.
            $table->foreignId('recommended_level_id')->nullable()->constrained('tahfiz_levels')->nullOnDelete();
            $table->unsignedTinyInteger('recommended_juz_start')->nullable();
            $table->string('recommended_target', 150)->nullable();
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['participant_id', 'status']);
        });

        // Skor per kriteria placement test (tilawah / hafalan / tahsin).
        Schema::create('tahfiz_placement_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_test_id')->constrained('tahfiz_placement_tests')->cascadeOnDelete();
            $table->string('aspect', 50); // tilawah / hafalan / tahsin
            $table->string('criterion', 100);
            $table->decimal('score', 5, 2);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('placement_test_id');
        });

        // ================= JADWAL & SESI =================
        // Jadwal berulang per jenis aktivitas (setoran, murojaah, tahsin, …).
        Schema::create('tahfiz_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('halaqah_id')->nullable()->constrained('tahfiz_halaqahs')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('tahfiz_programs')->cascadeOnDelete();
            $table->enum('activity_type', [
                'setoran', 'murojaah', 'tahsin', 'tilawah', 'ujian', 'mentoring', 'halaqah',
            ]);
            $table->unsignedTinyInteger('day_of_week'); // 0=Ahad … 6=Sabtu
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('instructor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['halaqah_id', 'day_of_week']);
            $table->index(['program_id', 'status']);
        });

        // Sesi konkret: satu pertemuan pada satu tanggal. Kehadiran & setoran
        // bergantung ke sesi, bukan ke jadwal berulang.
        Schema::create('tahfiz_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('halaqah_id')->nullable()->constrained('tahfiz_halaqahs')->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('tahfiz_schedules')->nullOnDelete();
            $table->enum('activity_type', [
                'setoran', 'murojaah', 'tahsin', 'tilawah', 'ujian', 'mentoring', 'halaqah',
            ]);
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('instructor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('topic', 150)->nullable();
            $table->enum('status', ['planned', 'ongoing', 'done', 'cancelled'])->default('planned');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['halaqah_id', 'date']);
            $table->index(['date', 'status']);
        });

        // ================= KEHADIRAN =================
        Schema::create('tahfiz_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->nullable()->constrained('tahfiz_sessions')->cascadeOnDelete();
            $table->foreignId('halaqah_id')->nullable()->constrained('tahfiz_halaqahs')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->date('date');
            $table->enum('status', ['hadir', 'izin', 'sakit', 'alpa', 'terlambat']);
            $table->time('arrival_time')->nullable();
            $table->string('permission_reason', 150)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['participant_id', 'date']);
            $table->index(['halaqah_id', 'date']);
        });

        // ================= TARGET =================
        // Target default program/halaqah. Bentuk & periodenya fleksibel.
        Schema::create('tahfiz_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->nullable()->constrained('tahfiz_programs')->cascadeOnDelete();
            $table->foreignId('halaqah_id')->nullable()->constrained('tahfiz_halaqahs')->cascadeOnDelete();
            $table->enum('scope', ['program', 'halaqah'])->default('program');
            $table->enum('period', ['daily', 'weekly', 'monthly', 'semester', 'program'])->default('weekly');
            $table->enum('target_type', ['juz', 'surah', 'ayat', 'page', 'line']);
            $table->foreignId('surah_id')->nullable()->constrained('quran_surahs')->nullOnDelete();
            $table->unsignedSmallInteger('ayah_start')->nullable();
            $table->unsignedSmallInteger('ayah_end')->nullable();
            $table->unsignedTinyInteger('juz')->nullable();
            $table->unsignedSmallInteger('page_start')->nullable();
            $table->unsignedSmallInteger('page_end')->nullable();
            $table->decimal('value', 8, 2)->nullable(); // mis. 2 halaman / minggu
            $table->string('unit', 20)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['program_id', 'is_active']);
            $table->index(['halaqah_id', 'is_active']);
        });

        // Target per peserta. Setiap peserta boleh punya target berbeda —
        // jangan memaksa satu halaqah memiliki target yang sama.
        Schema::create('tahfiz_individual_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->nullable()->constrained('tahfiz_enrollments')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('tahfiz_participants')->cascadeOnDelete();
            $table->enum('period', ['daily', 'weekly', 'monthly', 'semester', 'program'])->default('weekly');
            $table->enum('target_type', ['juz', 'surah', 'ayat', 'page', 'line']);
            $table->foreignId('surah_id')->nullable()->constrained('quran_surahs')->nullOnDelete();
            $table->unsignedSmallInteger('ayah_start')->nullable();
            $table->unsignedSmallInteger('ayah_end')->nullable();
            $table->unsignedTinyInteger('juz')->nullable();
            $table->unsignedSmallInteger('page_start')->nullable();
            $table->unsignedSmallInteger('page_end')->nullable();
            $table->decimal('value', 8, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['participant_id', 'is_active']);
            $table->index('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tahfiz_individual_targets');
        Schema::dropIfExists('tahfiz_targets');
        Schema::dropIfExists('tahfiz_attendance');
        Schema::dropIfExists('tahfiz_sessions');
        Schema::dropIfExists('tahfiz_schedules');
        Schema::dropIfExists('tahfiz_placement_scores');
        Schema::dropIfExists('tahfiz_placement_tests');
        Schema::dropIfExists('tahfiz_halaqah_members');
        Schema::dropIfExists('tahfiz_enrollments');
        Schema::dropIfExists('tahfiz_halaqahs');
        Schema::dropIfExists('tahfiz_registration_requests');
        Schema::dropIfExists('tahfiz_participant_profiles');
        Schema::dropIfExists('tahfiz_participants');
    }
};
