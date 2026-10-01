<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral eksternal & daftar penyedia rujukan.
 *
 * Hasil rujukan berupa ringkasan yang boleh dibagikan; rekam medis tetap menjadi
 * milik Modul Clinic/Health (source of truth), dan BK hanya menyimpan penunjuk.
 *
 * Catatan desain: kolom status/kategori memakai `string` dengan daftar nilai yang
 * dijaga PHP Enum, bukan `enum()` basis data. Alasannya: modul BK sudah menambah
 * nilai beberapa kali, dan CHECK constraint bawaan `enum()` membuat penambahan
 * nilai baru selalu berarti migrasi pemecahan tabel — di MySQL maupun SQLite.
 * Validasi tetap ada, hanya dipindahkan ke tempat yang benar (enum + Form Request).
 *
 * `school_id` nullable adalah penopang isolasi unit sekolah. Instalasi satu
 * sekolah membiarkannya kosong dan perilaku modelnya sama seperti sebelumnya;
 * instalasi yayasan dengan beberapa unit mengisinya, dan global scope
 * `BelongsToSchool` menutup data antar-unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bk_referral_providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('name', 160);
            $table->string('provider_type', 32);                    // PSYCHOLOGIST|DOCTOR|SPECIALIST|EXTERNAL_COUNSELOR|SOCIAL_SERVICE|EDUCATIONAL_INSTITUTION|OTHER
            $table->string('contact_person', 160)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 160)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_external')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_consent')->default(true);
            $table->string('confidentiality_level', 24)->default('CONFIDENTIAL');
            $table->json('service_scope')->nullable();
            $table->string('sla_response_days', 8)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name'], 'bk_referral_providers_name');
            $table->index(['provider_type', 'is_active'], 'bk_referral_providers_type');
        });

        Schema::create('bk_external_referrals', function (Blueprint $table): void {
            $table->id();
            $table->string('referral_number', 32)->unique();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('referral_provider_id')->nullable()->constrained('bk_referral_providers')->nullOnDelete();
            $table->foreignId('consent_id')->nullable()->constrained('bk_consents')->nullOnDelete();
            $table->string('status', 24)->default('DRAFT');         // DRAFT|ASSESSMENT|PENDING_CONSENT|REFERRED|APPOINTMENT_SCHEDULED|IN_PROGRESS|RESULT_RECEIVED|FOLLOW_UP|COMPLETED|CANCELLED|DECLINED
            $table->string('urgency', 16)->default('NORMAL');
            $table->text('reason')->nullable();
            $table->text('assessment')->nullable();
            $table->date('referral_date')->nullable();
            $table->timestamp('referred_at')->nullable();
            $table->timestamp('consent_obtained_at')->nullable();
            $table->timestamp('appointment_at')->nullable();
            $table->string('appointment_location', 160)->nullable();
            $table->timestamp('result_received_at')->nullable();
            /*
             | Hasil dari pihak luar disimpan sebagai ringkasan naratif yang
             | disetujui untuk dibagikan, BUKAN rekam medis. Diagnosa, resep, dan
             | riwayat klinis tetap milik Modul Clinic/Health; yang tersimpan di
             | sini hanya penunjuk (`clinic_reference`) bila memang ada di sana.
             */
            $table->text('outcome')->nullable();
            $table->text('external_summary')->nullable();
            $table->string('clinic_reference', 64)->nullable();
            $table->text('follow_up_plan')->nullable();
            $table->foreignId('referral_document_id')->nullable()->constrained('bk_case_documents')->nullOnDelete();
            $table->foreignId('result_document_id')->nullable()->constrained('bk_case_documents')->nullOnDelete();
            $table->foreignId('follow_up_id')->nullable()->constrained('bk_follow_ups')->nullOnDelete();
            $table->boolean('outcome_shared_with_parent')->default(false);
            $table->string('confidentiality_level', 24)->default('CONFIDENTIAL');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['case_id', 'status'], 'bk_ext_referrals_case');
            $table->index(['student_id', 'status'], 'bk_ext_referrals_student');
            $table->index(['school_id', 'status'], 'bk_ext_referrals_school');
            $table->index('appointment_at', 'bk_ext_referrals_appointment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_external_referrals');
        Schema::dropIfExists('bk_referral_providers');
    }
};
