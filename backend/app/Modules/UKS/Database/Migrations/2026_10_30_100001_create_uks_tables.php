<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul UKS (Usaha Kesehatan Sekolah) — seluruh tabel berprefix `uks_`.
 *
 * Bersifat **tambahan**: tidak ada tabel inti yang diubah, sehingga migrasi aman
 * dijalankan pada basis data yang sudah berjalan. Kolom jenis/status memakai
 * `string` (bukan `enum`) — nilainya diikat oleh enum PHP di
 * `App\Modules\UKS\Domain\Enums`, sehingga penambahan jenis tidak butuh ALTER.
 *
 * Privasi: keluhan dan pemeriksaan bersifat rahasia. Siapa yang boleh melihat
 * baris mana ditegakkan di `UksAccessService` (petugas/admin melihat semua,
 * guru hanya siswa kelas yang diampunya, orang tua hanya anaknya, siswa hanya
 * dirinya sendiri) — bukan di skema.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Petugas UKS (guru/staf/nakes yang bertugas) ----------
        Schema::create('uks_officers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->string('role', 20)->default('PETUGAS'); // PETUGAS | KOORDINATOR | NAKES
            $table->string('license_no', 60)->nullable();   // nomor STR/SIP untuk nakes
            $table->boolean('is_active')->default(true);
            $table->date('appointed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'role']);
        });

        // ---------- Profil kesehatan siswa (satu baris per siswa) ----------
        Schema::create('uks_health_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('students')->cascadeOnDelete();
            $table->string('blood_type', 3)->nullable();
            $table->text('allergies')->nullable();
            $table->text('chronic_conditions')->nullable();
            $table->text('regular_medications')->nullable();
            $table->text('special_needs')->nullable();
            $table->string('insurance_provider', 80)->nullable();
            $table->string('insurance_number', 40)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            $table->string('emergency_contact_relation', 40)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ---------- Master obat & stok ----------
        Schema::create('uks_medicines', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('category', 60)->nullable();
            $table->string('unit', 20)->default('tablet');
            $table->decimal('stock', 12, 2)->default(0);
            $table->decimal('min_stock', 12, 2)->default(0);
            $table->date('expiry_date')->nullable();
            $table->string('storage_location', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'name']);
            $table->index('expiry_date');
        });

        // ---------- Mutasi stok (buku persediaan) ----------
        Schema::create('uks_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('medicine_id')->constrained('uks_medicines')->cascadeOnDelete();
            $table->string('direction', 6); // MASUK | KELUAR
            $table->decimal('quantity', 12, 2);
            $table->decimal('stock_after', 12, 2);
            $table->string('reason', 30); // PENGADAAN | PEMAKAIAN | PENYESUAIAN | KEDALUWARSA | RUSAK
            $table->foreignId('visit_id')->nullable()->constrained('uks_visits')->nullOnDelete();
            $table->string('reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['medicine_id', 'created_at']);
        });

        // ---------- Kunjungan UKS (inti operasional) ----------
        Schema::create('uks_visits', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->string('visit_type', 20)->default('KELUHAN'); // KELUHAN | KECELAKAAN | RUJUKAN_GURU | KONTROL
            $table->string('complaint', 255);
            $table->text('symptoms')->nullable();
            $table->string('severity', 12)->default('RINGAN'); // RINGAN | SEDANG | BERAT | DARURAT
            $table->dateTime('arrived_at');
            $table->dateTime('left_at')->nullable();
            $table->string('status', 16)->default('MENUNGGU'); // MENUNGGU | DITANGANI | ISTIRAHAT | PULANG | DIRUJUK | SELESAI
            $table->text('treatment')->nullable();
            $table->text('advice')->nullable();
            $table->boolean('needs_rest')->default(false);
            $table->boolean('sent_home')->default(false);
            $table->boolean('parent_notified')->default(false);
            $table->dateTime('parent_notified_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'arrived_at']);
            $table->index(['status', 'arrived_at']);
            $table->index(['school_class_id', 'arrived_at']);
            $table->index('severity');
        });

        // ---------- Obat yang diberikan pada kunjungan ----------
        Schema::create('uks_visit_medications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('uks_visits')->cascadeOnDelete();
            $table->foreignId('medicine_id')->nullable()->constrained('uks_medicines')->nullOnDelete();
            $table->string('medicine_name', 150);
            $table->string('dosage', 80)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 20)->nullable();
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('given_at')->nullable();
            $table->timestamps();

            $table->index('visit_id');
        });

        // ---------- Persetujuan orang tua (per kunjungan) ----------
        Schema::create('uks_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('uks_visits')->cascadeOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->string('decision', 16); // DISETUJUI | DITOLAK
            $table->text('note')->nullable();
            $table->string('channel', 16)->default('APLIKASI'); // APLIKASI | TELEPON | TATAP_MUKA
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at');
            $table->timestamps();

            $table->index(['visit_id', 'decision']);
        });

        // ---------- Rujukan ke fasilitas kesehatan ----------
        Schema::create('uks_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained('uks_visits')->cascadeOnDelete();
            $table->string('facility_name', 150);
            $table->string('facility_type', 30)->nullable(); // PUSKESMAS | KLINIK | RS | LAINNYA
            $table->text('reason');
            $table->string('status', 16)->default('DIAJUKAN'); // DIAJUKAN | BERANGKAT | SELESAI | DIBATALKAN
            $table->dateTime('referred_at');
            $table->dateTime('returned_at')->nullable();
            $table->text('outcome')->nullable();
            $table->string('document_path')->nullable();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'referred_at']);
        });

        // ---------- Pemeriksaan berkala (skrining TB/BB, gigi, mata, ...) ----------
        Schema::create('uks_screenings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->string('screening_type', 20); // UMUM | GIGI | MATA | TBC | GIZI | LAINNYA
            $table->date('screened_at');
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->string('vision_left', 10)->nullable();
            $table->string('vision_right', 10)->nullable();
            $table->string('dental_status', 40)->nullable();
            $table->string('result', 16)->default('NORMAL'); // NORMAL | PERLU_PERHATIAN | RUJUK
            $table->text('findings')->nullable();
            $table->text('recommendation')->nullable();
            $table->foreignId('screened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'screened_at']);
            $table->index(['screening_type', 'screened_at']);
            $table->index(['school_class_id', 'screened_at']);
        });

        // ---------- Imunisasi ----------
        Schema::create('uks_immunizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('vaccine_name', 120);
            $table->unsignedTinyInteger('dose_number')->default(1);
            $table->date('given_at');
            $table->string('batch_no', 40)->nullable();
            $table->string('provider', 120)->nullable(); // puskesmas/pihak pemberi
            $table->date('next_due_at')->nullable();
            $table->text('reaction')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'given_at']);
            $table->index('next_due_at');
        });

        // ---------- Insiden/kecelakaan di sekolah ----------
        Schema::create('uks_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('uks_visits')->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->string('location', 120)->nullable();
            $table->string('severity', 12)->default('RINGAN');
            $table->text('description');
            $table->text('first_aid')->nullable();
            $table->text('witnesses')->nullable();
            $table->boolean('parent_notified')->default(false);
            $table->string('status', 16)->default('TERBUKA'); // TERBUKA | DITINDAKLANJUTI | SELESAI
            $table->text('follow_up')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'occurred_at']);
            $table->index(['status', 'occurred_at']);
        });

        // ---------- Surat keterangan sakit / istirahat ----------
        Schema::create('uks_letters', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('visit_id')->nullable()->constrained('uks_visits')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('letter_type', 20)->default('ISTIRAHAT'); // ISTIRAHAT | SAKIT | RUJUKAN
            $table->date('date_from');
            $table->date('date_to');
            $table->text('diagnosis_note')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('verification_code', 12)->unique();
            $table->string('file_path')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'date_from']);
        });
    }

    public function down(): void
    {
        foreach ([
            'uks_letters', 'uks_incidents', 'uks_immunizations', 'uks_screenings',
            'uks_stock_movements', 'uks_medicines', 'uks_referrals', 'uks_consents',
            'uks_visit_medications', 'uks_visits', 'uks_health_profiles', 'uks_officers',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
