<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Mutation — mutasi siswa.
 *
 * `student_transfers` menyimpan permohonan mutasi (masuk, keluar, pindah
 * kelas, pindah sekolah) beserta jejak persetujuannya. Efek mutasi terhadap
 * siswa/rombel baru diterapkan saat permohonan berstatus `completed`, bukan
 * saat dibuat, sehingga satu tabel cukup untuk semua jenis mutasi.
 *
 * `student_transfer_histories` adalah jejak audit perpindahan status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['masuk', 'keluar', 'pindah_kelas', 'pindah_sekolah']);
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('to_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->string('from_school_name', 150)->nullable();
            $table->string('to_school_name', 150)->nullable();
            $table->date('request_date');
            $table->date('effective_date')->nullable();
            $table->text('reason')->nullable();
            $table->string('document_number', 50)->nullable()->unique();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'completed'])
                ->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'status']);
            $table->index(['type', 'status']);
        });

        Schema::create('student_transfer_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_transfer_id')->constrained('student_transfers')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('student_transfer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_transfer_histories');
        Schema::dropIfExists('student_transfers');
    }
};
