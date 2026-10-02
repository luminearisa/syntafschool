<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masa kerja, percobaan, dan kontrak (§16, §17, §36, §37).
 *
 * `hr_employment_records` adalah riwayat *hubungan kerja*, bukan salinan kolom
 * pegawai. Ahmad yang berhenti 2024 lalu kembali 2026 punya dua baris di sini —
 * dan pertanyaan "berapa lama total masa kerjanya?" dapat dijawab dengan
 * menjumlahkan periode, bukan menebak dari satu tanggal masuk.
 *
 * `hr_probations` dipisah dari kontrak karena masa percobaan punya siklusnya
 * sendiri (review → lulus / diperpanjang / gagal) yang harus dievaluasi, bukan
 * sekadar tanggal. Hasil review disimpan apa adanya; penilaian kinerja fase
 * berikutnya akan merujuk ke baris ini alih-alih membuat catatan baru.
 *
 * Kontrak menyimpan `document_id` (dokumen bertanda tangan) dan status
 * `expiring`/`expired` yang dihitung ulang oleh penjadwal harian — bukan diketik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employment_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('employment_number', 40)->nullable();
            $table->string('employment_type_code', 60)->comment('Referensi group employment_type');
            $table->string('worker_type_code', 60)->nullable()->comment('Referensi group worker_type');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->string('status', 30)->default('active')->comment('active | ended | extended | cancelled');
            $table->string('termination_reason_code', 60)->nullable()->comment('Referensi group exit_reason');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('hr_probations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('employment_record_id')->nullable()->constrained('hr_employment_records')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 30)->default('pending')->comment('pending | active | passed | extended | failed');
            $table->date('review_date')->nullable();
            $table->string('review_result', 500)->nullable();
            $table->date('extended_to')->nullable()->comment('Bila diperpanjang');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('end_date');
        });

        Schema::create('hr_contract_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->boolean('is_fixed_term')->default(true)->comment('Kontrak berjangka wajib punya tanggal berakhir');
            $table->unsignedSmallInteger('default_duration_months')->nullable();
            $table->unsignedSmallInteger('reminder_days_before')->default(30);
            $table->string('description', 400)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('contract_number', 60)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('contract_type_id')->constrained('hr_contract_types')->restrictOnDelete();
            $table->foreignId('employment_record_id')->nullable()->constrained('hr_employment_records')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->foreignId('supersedes_contract_id')->nullable()->constrained('hr_contracts')->nullOnDelete()
                ->comment('Perpanjangan menggantikan kontrak sebelumnya — riwayat tetap utuh');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('renewal_date')->nullable();
            $table->date('signed_date')->nullable();
            $table->string('status', 30)->default('draft')->comment('draft | active | expiring | expired | renewed | terminated | cancelled');
            $table->unsignedBigInteger('document_id')->nullable()->comment('Rujukan lunak ke hr_employee_documents');
            $table->boolean('is_signed')->default(false);
            $table->dateTime('reminder_sent_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'end_date']);
            $table->index('end_date');
        });

        /*
         * Fondasi cuti/ketidakhadiran: hanya jenis cutinya. Saldo, pengajuan, dan
         * persetujuan cuti milik fase berikutnya — tetapi `hr_leave_types` sudah
         * ada sekarang supaya mesin transaksi dapat menandai pegawai `leave`
         * dengan alasan yang sah, bukan teks bebas.
         */
        Schema::create('hr_leave_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_approval')->default(true);
            $table->unsignedSmallInteger('max_days_per_year')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('description', 400)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_types');
        Schema::dropIfExists('hr_contracts');
        Schema::dropIfExists('hr_contract_types');
        Schema::dropIfExists('hr_probations');
        Schema::dropIfExists('hr_employment_records');
    }
};
