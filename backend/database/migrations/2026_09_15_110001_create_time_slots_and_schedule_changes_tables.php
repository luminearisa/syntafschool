<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A2 — Jadwal Pelajaran.
 *
 * Menambah dua hal:
 * 1. `time_slots` — jam pelajaran (jam ke-1, istirahat, upacara) per hari.
 * 2. `schedule_changes` — perubahan jadwal: guru pengganti, pindah jam, pembatalan.
 *
 * Sekaligus menautkan `schedules` ke `time_slots` supaya grid mingguan bisa
 * menempatkan jadwal pada baris jam pelajaran yang benar.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= JAM PELAJARAN =================
        Schema::create('time_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('day_id')->constrained('days')->restrictOnDelete();
            $table->unsignedTinyInteger('slot_number');
            $table->string('label', 50)->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('type', ['pelajaran', 'istirahat', 'upacara', 'lainnya'])->default('pelajaran');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Satu hari tidak boleh punya dua jam pelajaran bernomor sama.
            $table->unique(['day_id', 'slot_number']);
            $table->index(['day_id', 'start_time']);
        });

        // ================= PERUBAHAN JADWAL =================
        Schema::create('schedule_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['pengganti', 'pindah', 'batal'])->default('pengganti');
            $table->foreignId('substitute_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('new_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('new_day_id')->nullable()->constrained('days')->nullOnDelete();
            $table->time('new_start_time')->nullable();
            $table->time('new_end_time')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Satu jadwal hanya boleh punya satu perubahan per tanggal.
            $table->unique(['schedule_id', 'date']);
            $table->index(['date', 'schedule_id']);
        });

        // ================= TAUTKAN JADWAL KE JAM PELAJARAN =================
        Schema::table('schedules', function (Blueprint $table) {
            $table->foreignId('time_slot_id')->nullable()->constrained('time_slots')->nullOnDelete();
            $table->string('note', 150)->nullable();

            // Index untuk query deteksi bentrok (kelas / guru / ruang per hari per semester).
            $table->index(['school_class_id', 'day_id', 'semester_id'], 'schedules_class_conflict_idx');
            $table->index(['employee_id', 'day_id', 'semester_id'], 'schedules_teacher_conflict_idx');
            $table->index(['room_id', 'day_id', 'semester_id'], 'schedules_room_conflict_idx');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('schedules_class_conflict_idx');
            $table->dropIndex('schedules_teacher_conflict_idx');
            $table->dropIndex('schedules_room_conflict_idx');
            $table->dropConstrainedForeignId('time_slot_id');
            $table->dropColumn('note');
        });

        Schema::dropIfExists('schedule_changes');
        Schema::dropIfExists('time_slots');
    }
};
