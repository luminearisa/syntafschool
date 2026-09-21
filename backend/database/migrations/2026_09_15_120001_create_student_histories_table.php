<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Riwayat akademik siswa per tahun ajaran.
        // Satu baris = posisi siswa pada satu tahun ajaran (kelas + status).
        Schema::create('student_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('education_level_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['baru', 'naik', 'tinggal', 'lulus', 'pindah', 'keluar'])->default('baru');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id']);
            $table->index(['academic_year_id', 'school_class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_histories');
    }
};
