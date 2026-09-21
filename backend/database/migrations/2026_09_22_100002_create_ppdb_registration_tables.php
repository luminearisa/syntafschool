<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPDB — akun orang tua, calon peserta, pendaftaran, jawaban, dokumen, riwayat.
 *
 * Struktur inti yang dipertahankan: **User → Parent/Guardian → Applicant →
 * Registration**. Satu akun orang tua boleh punya banyak anak (applicant);
 * identitas orang tua TIDAK diduplikasi (memakai `users` + `guardians`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Profil PPDB untuk akun orang tua: hanya STATUS & catatan.
        // Identitas (nama/email/telepon) tetap dibaca dari users/guardians.
        Schema::create('ppdb_parent_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->enum('account_status', ['pending_verification', 'active', 'inactive', 'blocked'])
                ->default('pending_verification');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_status']);
        });

        Schema::create('ppdb_applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_account_id')->constrained('ppdb_parent_accounts')->cascadeOnDelete();
            // Wali/penanggung jawab; memakai master guardians existing.
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();

            $table->string('full_name', 150);
            $table->string('nickname', 50)->nullable();
            $table->foreignId('gender_id')->nullable()->constrained('genders')->nullOnDelete();
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nik', 20)->nullable();
            $table->string('nisn', 20)->nullable();
            $table->foreignId('religion_id')->nullable()->constrained('religions')->nullOnDelete();

            $table->text('address')->nullable();
            $table->string('province_id', 2)->nullable();
            $table->string('city_id', 4)->nullable();
            $table->string('district_id', 7)->nullable();
            $table->string('village_id', 10)->nullable();
            $table->string('postal_code', 10)->nullable();

            $table->string('previous_school', 150)->nullable();
            $table->string('previous_school_npsn', 20)->nullable();
            // Program yang dituju (penawaran PPDB).
            $table->foreignId('target_program_id')->nullable()->constrained('ppdb_programs')->nullOnDelete();

            $table->string('photo_path')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_account_id', 'status']);
            $table->index('nik');
            $table->index('nisn');
        });

        Schema::create('ppdb_registrations', function (Blueprint $table) {
            $table->id();
            // Nomor pendaftaran dibangkitkan dari `document_sequences` (numbering existing).
            $table->string('registration_number', 50)->unique();

            $table->foreignId('period_id')->constrained('ppdb_periods')->cascadeOnDelete();
            $table->foreignId('wave_id')->nullable()->constrained('ppdb_waves')->nullOnDelete();
            $table->foreignId('program_id')->constrained('ppdb_programs')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('ppdb_applicants')->cascadeOnDelete();
            $table->foreignId('parent_account_id')->constrained('ppdb_parent_accounts')->cascadeOnDelete();

            $table->enum('status', [
                'draft', 'submitted', 'under_review', 'need_revision', 'verified',
                'selection', 'passed', 'failed', 'waitlisted', 're_registration',
                'completed', 'cancelled',
            ])->default('draft');

            $table->enum('payment_status', [
                'unpaid', 'pending', 'paid', 'failed', 'expired', 'cancelled', 'refunded',
            ])->default('unpaid');

            $table->enum('document_status', ['not_uploaded', 'under_review', 'approved', 'rejected'])
                ->default('not_uploaded');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('selection_result', ['passed', 'failed', 'waitlisted'])->nullable();
            $table->timestamp('announced_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Siswa hasil konversi (diisi saat daftar ulang selesai).
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();

            $table->text('revision_note')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['period_id', 'status']);
            $table->index(['wave_id', 'status']);
            $table->index(['parent_account_id', 'status']);
            $table->index(['payment_status']);
            $table->index(['document_status']);
        });

        // Jawaban form (field builder) & pertanyaan tambahan disimpan seragam.
        Schema::create('ppdb_registration_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('ppdb_registrations')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('ppdb_questions')->cascadeOnDelete();
            $table->string('field_key', 50)->nullable();
            $table->text('value')->nullable();
            // Untuk jawaban multi-nilai (checkbox/multiple choice).
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->index(['registration_id', 'field_key']);
            $table->index(['registration_id', 'question_id']);
        });

        // Dokumen calon — ber-versi; versi lama tidak dihapus.
        Schema::create('ppdb_applicant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('ppdb_registrations')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('ppdb_applicants')->cascadeOnDelete();
            $table->foreignId('requirement_id')->nullable()->constrained('ppdb_document_requirements')->nullOnDelete();
            // Label disalin agar rekam jejak tetap terbaca bila konfigurasi diubah.
            $table->string('requirement_name', 150);

            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(true);

            $table->string('file_path');
            $table->string('original_filename', 255);
            $table->string('mime_type', 120);
            $table->unsignedInteger('file_size');

            $table->enum('status', ['uploaded', 'under_review', 'approved', 'rejected'])->default('uploaded');
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['registration_id', 'is_active']);
            $table->index(['status']);
        });

        Schema::create('ppdb_registration_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('ppdb_registrations')->cascadeOnDelete();
            $table->string('action', 60);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['registration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppdb_registration_histories');
        Schema::dropIfExists('ppdb_applicant_documents');
        Schema::dropIfExists('ppdb_registration_answers');
        Schema::dropIfExists('ppdb_registrations');
        Schema::dropIfExists('ppdb_applicants');
        Schema::dropIfExists('ppdb_parent_accounts');
    }
};