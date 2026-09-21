<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A11 — Sumber daya pembelajaran (fondasi tabel).
 *
 * Kelompok belajar & booking ruangan.
 *
 * Catatan kepemilikan tabel (jangan diduplikasi di sini):
 * - `assignments` + `assignment_submissions` → `2026_09_18_150002_create_assignment_tables.php` (A11b)
 * - `lesson_plans` + `teaching_journals`    → `2026_09_18_150001_create_teaching_journal_tables.php` (A11a)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= KELOMPOK BELAJAR =================
        Schema::create('study_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ================= BOOKING RUANGAN =================
        Schema::create('room_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 150);
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('purpose', 150)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->timestamps();

            $table->index(['room_id', 'date']);
            $table->index(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_bookings');
        Schema::dropIfExists('study_groups');
    }
};
