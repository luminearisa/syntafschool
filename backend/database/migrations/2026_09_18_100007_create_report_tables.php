<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A9 — Rapor, Transkrip & Dokumen Akademik (fondasi tabel).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= RAPOR =================
        Schema::create('report_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['tengah_semester', 'semester', 'akhir'])->default('semester');
            $table->enum('status', ['draft', 'in_progress', 'ready_for_review', 'approved', 'published'])->default('draft');
            $table->decimal('average_score', 5, 2)->nullable();
            $table->integer('rank')->nullable();
            $table->json('attendance_summary')->nullable(); // {"hadir":90,"sakit":2,...}
            $table->text('notes')->nullable();
            $table->text('homeroom_note')->nullable(); // catatan wali kelas
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['student_id', 'semester_id', 'type'], 'report_card_uniq');
            $table->index(['school_class_id', 'status']);
        });

        // ================= ITEM RAPOR (PER MAPEL) =================
        Schema::create('report_card_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('grade', 5)->nullable();
            $table->string('predicate', 50)->nullable();
            $table->text('description')->nullable(); // deskripsi capaian
            $table->text('teacher_notes')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['report_card_id', 'subject_id'], 'report_card_item_uniq');
        });

        // ================= TRANSKRIP =================
        Schema::create('transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained()->nullOnDelete();
            $table->json('data')->nullable(); // snapshot rapor per mapel
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'academic_year_id']);
        });

        // ================= DOKUMEN AKADEMIK =================
        Schema::create('academic_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('document_type', ['surat_aktif', 'surat_pindah', 'keterangan', 'sertifikat', 'legalisasi', 'lainnya'])->default('lainnya');
            $table->string('document_number', 50)->nullable();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->date('issue_date')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('issued_by', 150)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_documents');
        Schema::dropIfExists('transcripts');
        Schema::dropIfExists('report_card_items');
        Schema::dropIfExists('report_cards');
    }
};