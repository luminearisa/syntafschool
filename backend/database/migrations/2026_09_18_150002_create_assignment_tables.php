<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A11b — Tugas (assignment) & pengumpulan (submission).
 *
 * Migrasi ini PEMILIK tabel `assignments` dan `assignment_submissions`.
 * Migrasi `2026_09_18_100009_create_teaching_tables.php` hanya menangani
 * `study_groups` + `room_bookings` supaya tidak ada tabel yang dibuat dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= TUGAS =================
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('school_classes')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description');
            $table->text('instructions')->nullable();
            $table->enum('type', ['tugas', 'kuis', 'projek', 'praktik', 'portofolio'])->default('tugas');
            $table->decimal('max_score', 6, 2)->default(100);
            // Bobot terhadap nilai akhir (persen, 0–100).
            $table->decimal('weight', 5, 2)->default(0);
            $table->date('assigned_date');
            $table->date('due_date');
            $table->boolean('allow_late_submission')->default(false);
            $table->string('attachment_path')->nullable();
            $table->enum('status', ['draft', 'published', 'closed'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['class_id', 'semester_id']);
            $table->index(['subject_id', 'semester_id']);
            $table->index('due_date');
            $table->index('status');
        });

        // ================= PENGUMPULAN TUGAS =================
        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('content')->nullable();
            $table->string('attachment_path')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->enum('status', ['pending', 'submitted', 'late', 'graded', 'returned'])->default('pending');
            $table->timestamps();
            $table->softDeletes();

            // Satu siswa hanya punya satu pengumpulan per tugas.
            $table->unique(['assignment_id', 'student_id']);
            $table->index('student_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignments');
    }
};
