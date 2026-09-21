<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A6 — Presensi (fondasi tabel).
 *
 * Presensi siswa (per jadwal/pertemuan), presensi pegawai, dan koreksi
 * presensi dengan approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= PRESENSI SISWA =================
        Schema::create('student_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->foreignId('attendance_status_id')->constrained('attendance_statuses')->restrictOnDelete();
            $table->foreignId('leave_type_id')->nullable()->constrained()->nullOnDelete();
            $table->time('time_in')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('status', ['pending', 'verified', 'locked'])->default('pending');
            $table->timestamps();

            $table->index(['student_id', 'date']);
            $table->index(['school_class_id', 'date']);
            $table->index(['schedule_id', 'date']);
            $table->index(['semester_id', 'attendance_status_id']);
        });

        // ================= PRESENSI PEGAWAI =================
        Schema::create('employee_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->foreignId('attendance_status_id')->constrained('attendance_statuses')->restrictOnDelete();
            $table->foreignId('leave_type_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
        });

        // ================= KOREKSI PRESENSI =================
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_attendance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('old_status_id')->constrained('attendance_statuses')->restrictOnDelete();
            $table->foreignId('new_status_id')->constrained('attendance_statuses')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('employee_attendances');
        Schema::dropIfExists('student_attendances');
    }
};