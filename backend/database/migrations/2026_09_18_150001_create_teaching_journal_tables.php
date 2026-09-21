<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A11a — Jurnal Mengajar & RPP / Modul Ajar.
 *
 * Dua tabel inti:
 * 1. `lesson_plans`      — RPP / modul ajar beserta alur approval-nya.
 * 2. `teaching_journals` — jurnal mengajar harian + realisasi JP.
 *
 * Migrasi ini juga menambah kolom `class_subjects.weekly_hours` (alokasi JP
 * per mapel di sebuah kelas) yang dipakai untuk memvalidasi `realized_jp`
 * tidak melebihi beban mengajar. Bila kosong, alokasi jatuh ke `subjects.credit`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Alokasi JP per mapel di sebuah kelas. Nullable agar data lama tetap sah.
        Schema::table('class_subjects', function (Blueprint $table) {
            $table->unsignedSmallInteger('weekly_hours')->nullable();
        });

        // ================= RPP / MODUL AJAR =================
        Schema::create('lesson_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('education_level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('title', 200);
            $table->json('learning_objectives')->nullable();
            $table->json('activities')->nullable();
            $table->text('assessment_plan')->nullable();
            $table->text('media')->nullable();
            $table->text('references')->nullable();
            $table->unsignedSmallInteger('duration_jp')->default(1);
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('attachment_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'semester_id']);
            $table->index('subject_id');
            $table->index('status');
        });

        // ================= JURNAL MENGAJAR =================
        Schema::create('teaching_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_id')->constrained('school_classes')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('time_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('topic', 200);
            $table->text('material')->nullable();
            $table->text('activities')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('student_present_count')->default(0);
            $table->unsignedSmallInteger('realized_jp')->default(0);
            $table->enum('status', ['planned', 'ongoing', 'completed', 'cancelled'])->default('planned');
            $table->string('attachment_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Satu guru tidak boleh punya dua jurnal pada jam yang sama di tanggal yang sama.
            $table->unique(['teacher_id', 'date', 'time_slot_id'], 'teaching_journal_unique_slot');
            $table->index(['class_id', 'date']);
            $table->index(['teacher_id', 'date']);
            $table->index('semester_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_journals');
        Schema::dropIfExists('lesson_plans');

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->dropColumn('weekly_hours');
        });
    }
};
