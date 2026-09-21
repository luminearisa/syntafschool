<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2D — Kesiswaan & Progress Akademik (fondasi tabel).
 *
 * Prestasi, pelanggaran, ekstrakurikuler siswa, academic standing,
 * bimbingan akademik, dan early-warning alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= PRESTASI SISWA =================
        Schema::create('student_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('achievement_type_id')->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->date('date')->nullable();
            $table->string('level', 50)->nullable(); // Sekolah, Kecamatan, Provinsi, Nasional, Internasional
            $table->string('rank', 50)->nullable(); // Juara 1, Harapan 1, ...
            $table->string('organizer', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('certificate_path', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'date']);
        });

        // ================= PELANGGARAN SISWA =================
        Schema::create('student_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('violation_type_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->text('description')->nullable();
            $table->integer('point')->default(0);
            $table->foreignId('reported_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('action_taken')->nullable();
            $table->enum('status', ['recorded', 'verified', 'resolved'])->default('recorded');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'date']);
        });

        // ================= EKSTRAKURIKULER SISWA =================
        Schema::create('student_extracurriculars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extracurricular_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('grade', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['student_id', 'extracurricular_id', 'academic_year_id'], 'student_extracurricular_uniq');
        });

        // ================= ACADEMIC STANDING =================
        Schema::create('student_academic_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['baik', 'cukup', 'kurang', 'bermasalah'])->default('baik');
            $table->json('summary')->nullable(); // ringkasan nilai/kehadiran penanda standing
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'semester_id'], 'academic_standing_uniq');
        });

        // ================= BIMBINGAN AKADEMIK =================
        Schema::create('academic_advisories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('advisor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('type', ['konsultasi', 'bimbingan', 'intervensi'])->default('bimbingan');
            $table->string('title', 150);
            $table->text('description');
            $table->date('date');
            $table->text('follow_up')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'date']);
        });

        // ================= EARLY WARNING / ALERT =================
        Schema::create('academic_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['nilai_rendah', 'kehadiran_rendah', 'risiko_tidak_naik', 'pelanggaran'])->default('nilai_rendah');
            $table->enum('level', ['info', 'warning', 'critical'])->default('info');
            $table->string('message', 255);
            $table->json('data')->nullable();
            $table->timestamp('triggered_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'semester_id']);
            $table->index(['level', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_alerts');
        Schema::dropIfExists('academic_advisories');
        Schema::dropIfExists('student_academic_standings');
        Schema::dropIfExists('student_extracurriculars');
        Schema::dropIfExists('student_violations');
        Schema::dropIfExists('student_achievements');
    }
};