<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A8 — Bank Soal & Ujian (fondasi tabel).
 *
 * Soal, paket ujian, item paket, ruang ujian, jadwal ujian, peserta ujian.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= BANK SOAL =================
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->enum('question_type', ['pilihan_ganda', 'isian', 'essay', 'uraian'])->default('pilihan_ganda');
            $table->text('question');
            $table->json('options')->nullable(); // [{"key":"A","text":"..."}, ...]
            $table->string('correct_answer', 10)->nullable();
            $table->enum('difficulty', ['mudah', 'sedang', 'sulit'])->default('sedang');
            $table->string('topic', 100)->nullable();
            $table->string('bloom_level', 30)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_id', 'topic']);
            $table->index(['subject_id', 'difficulty']);
        });

        // ================= PAKET UJIAN =================
        Schema::create('question_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->text('description')->nullable();
            $table->integer('question_count')->default(0);
            $table->boolean('randomize')->default(true);
            $table->integer('duration_minutes')->default(90);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_id', 'semester_id']);
        });

        // ================= ITEM PAKET UJIAN =================
        Schema::create('question_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->decimal('score_weight', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['question_package_id', 'question_id'], 'question_package_item_uniq');
        });

        // ================= RUANG UJIAN =================
        Schema::create('exam_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('capacity')->default(0);
            $table->foreignId('supervisor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // ================= JADWAL UJIAN =================
        Schema::create('exam_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('question_package_id')->nullable()->constrained()->nullOnDelete();
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('supervisor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('status', ['scheduled', 'ongoing', 'done', 'cancelled'])->default('scheduled');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_class_id', 'exam_date']);
            $table->index(['semester_id', 'exam_date']);
        });

        // ================= PESERTA UJIAN =================
        Schema::create('exam_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('attendance_status', ['hadir', 'sakit', 'izin', 'alpa'])->default('hadir');
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['exam_schedule_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_participants');
        Schema::dropIfExists('exam_schedules');
        Schema::dropIfExists('exam_rooms');
        Schema::dropIfExists('question_package_items');
        Schema::dropIfExists('question_packages');
        Schema::dropIfExists('questions');
    }
};