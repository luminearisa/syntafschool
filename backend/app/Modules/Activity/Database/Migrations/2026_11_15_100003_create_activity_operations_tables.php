<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal, sesi pertemuan, materi, kehadiran, dan pengumuman aktivitas.
 *
 * Jadwal berulang (mis. setiap Senin 15:00) menghasilkan baris `activity_sessions`
 * yang nyata, karena kehadiran, materi, dan penilaian menempel pada pertemuan —
 * bukan pada pola jadwal. `generation_key` menjaga pembuatan sesi tetap idempoten
 * bila perintah dijalankan dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('activity_periods')->nullOnDelete();
            $table->tinyInteger('day_of_week'); // 1 = Senin ... 7 = Minggu
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('coach_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('location')->nullable();
            $table->string('session_type', 24)->default('REGULAR'); // REGULAR|SPECIAL|HOLIDAY|EXAM
            $table->boolean('is_recurring')->default(true);
            $table->date('effective_start')->nullable();
            $table->date('effective_end')->nullable();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['program_id', 'day_of_week'], 'activity_schedules_lookup');
        });

        Schema::create('activity_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('activity_periods')->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('activity_schedules')->nullOnDelete();
            $table->integer('session_number');
            $table->date('date')->index();
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('location')->nullable();
            $table->foreignId('coach_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('topic')->nullable();
            $table->text('objective')->nullable();
            $table->string('status', 24)->default('SCHEDULED')->index();
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('attendance_locked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('generation_key', 96)->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'session_number'], 'activity_sessions_number_unique');
            $table->unique(['generation_key'], 'activity_sessions_generation_unique');
        });

        Schema::create('activity_session_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('activity_sessions')->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 24)->default('DOCUMENT'); // DOCUMENT|LINK|VIDEO|PRESENTATION|FILE|LMS_CONTENT
            $table->text('description')->nullable();
            $table->string('url')->nullable();
            $table->string('file_path')->nullable();
            $table->string('lms_reference')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('activity_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('activity_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('activity_memberships')->nullOnDelete();
            $table->string('status', 24)->index(); // PRESENT|ABSENT|EXCUSED|LATE|SICK|PERMISSION
            $table->integer('minutes_late')->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 24)->default('MANUAL'); // MANUAL|QR|NFC|RFID|IMPORT
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->useCurrent();
            $table->string('idempotency_key', 96)->nullable();
            $table->string('attendance_reference')->nullable(); // id rekaman di modul Attendance
            $table->timestamps();
            $table->unique(['session_id', 'student_id'], 'activity_attendance_unique');
            $table->unique(['idempotency_key'], 'activity_attendance_idempotency_unique');
        });

        Schema::create('activity_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('audience', 24)->default('MEMBERS'); // MEMBERS|COACHES|PARENTS|ALL
            $table->boolean('notify')->default(true);
            $table->json('attachments')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_announcements');
        Schema::dropIfExists('activity_attendance');
        Schema::dropIfExists('activity_session_materials');
        Schema::dropIfExists('activity_sessions');
        Schema::dropIfExists('activity_schedules');
    }
};
