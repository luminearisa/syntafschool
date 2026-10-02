<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen pegawai dan versinya (§31, §32).
 *
 * Dua tabel, bukan satu. Dokumen kontrak kerja yang diperpanjang tiga kali bukan
 * baris yang ditimpa, melainkan satu dokumen dengan tiga versi — dan versi lama
 * tetap dapat dibuka ketika terjadi sengketa. Setiap versi menyimpan `checksum`
 * dan `reason`, sehingga pertanyaan "berkas ini berubah sejak kapan, oleh siapa,
 * dan mengapa?" terjawab tanpa membaca log server.
 *
 * `visibility` menegakkan kerahasiaan di lapisan data (`private` hanya untuk HR
 * berizin), bukan disaring di tampilan: berkas sensitif tidak pernah dikirim ke
 * pemanggil yang tidak berhak (§52).
 *
 * `hr_document_types` adalah konfigurasi, bukan enum: sekolah dapat menambah
 * "Surat Izin Mengajar" tanpa deploy — dan menandainya wajib bagi worker type
 * tertentu sehingga pemeriksa kualitas data dapat mengeluh dengan tepat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_document_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('category', 40)->comment('identity | employment | contract | education | certification | tax | insurance | health | policy | disciplinary | termination | other');
            $table->boolean('requires_number')->default(false);
            $table->boolean('requires_expiry')->default(false);
            $table->boolean('is_mandatory')->default(false)->comment('Wajib dimiliki setiap pegawai');
            $table->json('applies_to')->nullable()->comment('Batasan worker type / employment type / kode job');
            $table->unsignedSmallInteger('retention_months')->nullable()->comment('Kebijakan retensi; dipakai arsip, bukan penghapusan otomatis');
            $table->string('default_visibility', 20)->default('hr')->comment('private | hr | manager | employee | public');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('description', 400)->nullable();
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        Schema::create('hr_employee_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('hr_document_types')->restrictOnDelete();
            $table->string('title', 200)->nullable();
            $table->string('document_number', 100)->nullable();
            $table->string('issued_by', 180)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('status', 30)->default('active')->comment('active | draft | expiring | expired | revoked | replaced | archived');
            $table->string('visibility', 20)->default('hr')->comment('private | hr | manager | employee | public');
            $table->unsignedSmallInteger('current_version')->default(1);
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->string('verification_notes', 400)->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->dateTime('last_reminder_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('replaces_document_id')->nullable()->constrained('hr_employee_documents')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['document_type_id', 'status']);
            $table->index('expiry_date');
            $table->index(['verified', 'status']);
        });

        Schema::create('hr_employee_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('hr_employee_documents')->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('file_path', 300);
            $table->string('original_name', 250)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable()->comment('SHA-256 berkas — deteksi berkas berubah di luar aplikasi');
            $table->string('reason', 300)->nullable()->comment('Mengapa versi baru diunggah');
            $table->boolean('is_current')->default(true);
            $table->string('status', 20)->default('active')->comment('active | superseded | rejected');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('uploaded_at')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'version']);
            $table->index(['document_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_document_versions');
        Schema::dropIfExists('hr_employee_documents');
        Schema::dropIfExists('hr_document_types');
    }
};
