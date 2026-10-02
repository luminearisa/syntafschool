<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keterampilan dan sertifikasi (§29, §30, §84).
 *
 * `hr_employee_skills.proficiency_level_code` memakai skala yang dapat diubah
 * sekolah (beginner → expert), dan `last_assessed_at` menandai kapan terakhir
 * dinilai. Dua kolom itu cukup untuk membuka jalan *skills-based HR* fase
 * berikutnya:
 *
 * ```
 * Job.required_skills → Skill Gap → Learning → Career Path → Succession
 * ```
 *
 * Sertifikasi dibedakan menjadi **jenis** (`hr_certifications`, mis. "Sertifikat
 * Pendidik") dan **milik pegawai** (`hr_employee_certifications`, mis. sertifikat
 * Ahmad yang berakhir 12 Agustus). Pemisahan ini yang membuat pertanyaan "siapa
 * yang sertifikatnya berakhir bulan ini?" dapat dijawab tanpa memindai teks bebas
 * — dan membuat aturan "guru wajib bersertifikat pendidik" dapat diperiksa mesin
 * (§57).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_skills', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('category', 60)->nullable()->comment('technical | behavioral | language | religious | leadership | other');
            $table->string('skill_group', 80)->nullable()->comment('Kelompok bebas, mis. "Bahasa", "TI", "Tahfiz"');
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        Schema::create('hr_employee_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('hr_skills')->cascadeOnDelete();
            $table->string('proficiency_level_code', 30)->default('BEGINNER')
                ->comment('Referensi group skill_proficiency: BEGINNER | INTERMEDIATE | ADVANCED | EXPERT');
            $table->decimal('years_experience', 4, 1)->nullable();
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->date('last_assessed_at')->nullable();
            $table->string('source', 40)->default('self')->comment('self | manager | assessment | certification | import');
            $table->string('notes', 300)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'skill_id']);
            $table->index(['skill_id', 'proficiency_level_code']);
            $table->index('last_assessed_at');
        });

        Schema::create('hr_certifications', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 180);
            $table->string('category', 60)->nullable()->comment('teacher | professional | it | safety | first_aid | license | competency | religious | other');
            $table->string('issuer', 180)->nullable();
            $table->boolean('requires_expiry')->default(true);
            $table->unsignedSmallInteger('validity_months')->nullable()->comment('Masa berlaku bawaan');
            $table->boolean('is_mandatory_for_teachers')->default(false)->comment('Dipakai aturan validasi "guru wajib bersertifikat"');
            $table->json('applies_to')->nullable()->comment('Batasan opsional: worker type / kode job');
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        Schema::create('hr_employee_certifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('certification_id')->nullable()->constrained('hr_certifications')->nullOnDelete();
            $table->string('certificate_name', 180)->comment('Dipertahankan bila sertifikat belum ada di master');
            $table->string('issuer', 180)->nullable();
            $table->string('certificate_number', 100)->nullable();
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('status', 30)->default('valid')->comment('valid | expiring | expired | revoked | pending_verification');
            $table->unsignedBigInteger('document_id')->nullable()->comment('Rujukan lunak ke hr_employee_documents');
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->string('notes', 300)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index('expiry_date');
            $table->index('verified');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_certifications');
        Schema::dropIfExists('hr_certifications');
        Schema::dropIfExists('hr_employee_skills');
        Schema::dropIfExists('hr_skills');
    }
};
