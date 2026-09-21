<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A6 — Kolom & indeks tambahan untuk presensi.
 *
 * Tabel dasarnya dibuat oleh `2026_09_18_100005_create_attendance_tables.php`.
 * Migrasi ini hanya MENAMBAH, tidak membuat ulang.
 *
 * Catatan penting soal keunikan presensi:
 * SQLite (dan SQL standar) menganggap NULL berbeda dari NULL, sehingga unique
 * index `(student_id, date, schedule_id)` **tidak** mencegah duplikat ketika
 * `schedule_id` bernilai NULL. Karena itu aturan "satu presensi per siswa per
 * tanggal per jam" tetap ditegakkan di lapisan aplikasi
 * (`StudentAttendanceController::assertNoDuplicate()`), dan index di sini
 * berfungsi sebagai pengaman tambahan untuk presensi yang terikat jadwal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->foreignId('verified_by')->nullable()->after('recorded_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });

        Schema::table('student_attendances', function (Blueprint $table) {
            $table->index(['date'], 'student_attendance_date_idx');
            $table->index(['semester_id', 'date'], 'student_attendance_semester_date_idx');
            $table->unique(['student_id', 'date', 'schedule_id'], 'student_attendance_unique_idx');
        });

        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->index(['employee_id', 'date'], 'employee_attendance_employee_date_idx');
            $table->unique(['employee_id', 'date'], 'employee_attendance_unique_idx');
        });

        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->index(['status'], 'attendance_correction_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropIndex('attendance_correction_status_idx');
        });

        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->dropUnique('employee_attendance_unique_idx');
            $table->dropIndex('employee_attendance_employee_date_idx');
        });

        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropUnique('student_attendance_unique_idx');
            $table->dropIndex('student_attendance_semester_date_idx');
            $table->dropIndex('student_attendance_date_idx');
        });

        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn('verified_at');
        });
    }
};
