<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog pekerjaan (§9–§11).
 *
 * Empat tingkat yang harus dibedakan — dan sering keliru disatukan:
 *
 * ```
 * Job Family   "Academic"          bidang pekerjaan
 * Job          "Guru Matematika"   sebutan pekerjaan (dipakai berulang)
 * Job Level    "Senior"            jenjang karier
 * Job Grade    "G3"                golongan, nantinya dirujuk kompensasi
 * Position     "GURU-MTK-SMP-001"  kursi nyata di unit tertentu  → migrasi berikutnya
 * ```
 *
 * Tidak ada satu pun nominal rupiah di sini. Phase 1 hanya menyiapkan *struktur
 * rujukan* (`salary_band_reference`) yang akan dibaca Payroll/Compensation pada
 * fase berikutnya; menyimpan angka gaji sekarang berarti menebak kebijakan yang
 * belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_job_families', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 400)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('hr_job_levels', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->unsignedSmallInteger('rank')->default(0)->comment('Makin besar makin tinggi jenjangnya');
            $table->string('career_track', 40)->nullable()->comment('individual | managerial | specialist | academic');
            $table->string('description', 400)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['rank', 'status']);
        });

        Schema::create('hr_job_grades', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('level_code', 40)->nullable()->comment('Rujukan lunak hr_job_levels.code');
            $table->string('salary_band_reference', 60)->nullable()->comment('Rujukan struktur kompensasi — bukan nominal');
            $table->unsignedSmallInteger('rank')->default(0);
            $table->string('description', 400)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('hr_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('title', 180);
            $table->string('description', 1000)->nullable();
            $table->string('job_family_code', 40)->nullable();
            $table->string('job_level_code', 40)->nullable();
            $table->string('job_grade_code', 40)->nullable();
            $table->string('employment_category', 40)->nullable()->comment('Referensi group employment_type');
            // Syarat jabatan: dipakai Recruitment (fase 2) dan pemeriksa kualitas
            // data Phase 1 ("Guru wajib bersertifikat pendidik").
            $table->string('minimum_education', 40)->nullable()->comment('Referensi group education_level');
            $table->unsignedSmallInteger('required_experience_years')->nullable();
            $table->json('required_skills')->nullable()->comment('Kode skill hr_skills');
            $table->json('required_certifications')->nullable()->comment('Kode hr_certifications');
            $table->boolean('is_teacher_role')->default(false)->comment('Menjadi acuan aturan validasi pendidikan');
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['job_family_code', 'status']);
            $table->index(['job_level_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_jobs');
        Schema::dropIfExists('hr_job_grades');
        Schema::dropIfExists('hr_job_levels');
        Schema::dropIfExists('hr_job_families');
    }
};
