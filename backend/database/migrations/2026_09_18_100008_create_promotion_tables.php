<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A10 — Promotion Engine (fondasi tabel).
 *
 * Aturan kenaikan, keputusan kenaikan (dengan snapshot), approval berjenjang,
 * dan kelulusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= ATURAN KENAIKAN KELAS =================
        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('education_level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->decimal('min_average_score', 5, 2)->default(70);
            $table->decimal('min_attendance_percent', 5, 2)->default(80);
            $table->integer('max_unpassed_subjects')->default(3); // maksimal mapel tidak tuntas
            $table->decimal('min_passing_grade', 5, 2)->default(75);
            $table->integer('violation_point_limit')->nullable(); // batas poin pelanggaran
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['academic_year_id', 'is_active']);
        });

        // ================= KEPUTUSAN KENAIKAN =================
        Schema::create('promotion_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete(); // kelas asal
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_class_id')->nullable()->constrained('school_classes')->nullOnDelete(); // kelas tujuan (bila naik)
            $table->enum('decision', ['naik', 'tinggal', 'lulus', 'tidak_lulus'])->nullable();
            $table->json('snapshot')->nullable(); // nilai, kehadiran, poin saat keputusan diambil
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['draft', 'reviewed', 'approved', 'finalized'])->default('draft');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['student_id', 'academic_year_id'], 'promotion_decision_uniq');
            $table->index(['school_class_id', 'status']);
        });

        // ================= APPROVAL KENAIKAN =================
        Schema::create('promotion_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_decision_id')->constrained()->cascadeOnDelete();
            $table->string('approver_role', 50); // wali_kelas, kepala_sekolah
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['promotion_decision_id', 'approver_role'], 'promotion_approval_uniq');
        });

        // ================= KELULUSAN =================
        Schema::create('graduations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('graduation_number', 50)->nullable()->unique();
            $table->enum('decision', ['lulus', 'tidak_lulus']);
            $table->decimal('average_score', 5, 2)->nullable();
            $table->json('criteria_summary')->nullable();
            $table->string('certificate_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['student_id', 'academic_year_id'], 'graduation_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graduations');
        Schema::dropIfExists('promotion_approvals');
        Schema::dropIfExists('promotion_decisions');
        Schema::dropIfExists('promotion_rules');
    }
};