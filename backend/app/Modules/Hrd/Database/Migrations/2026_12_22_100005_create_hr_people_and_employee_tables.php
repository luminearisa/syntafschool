<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orang, pegawai, dan identitasnya (§12, §13, §75, §76).
 *
 * Tiga entitas yang dipisah dengan sengaja — inilah inti §87 "employee master
 * tidak boleh diduplikasi":
 *
 * ```
 * hr_people     siapa orangnya (identitas kependudukan, bisa jadi belum pegawai)
 * hr_employees  hubungan kerjanya dengan yayasan (nomor pegawai, unit, status)
 * users         akun login (opsional, lewat `user_id`)
 * ```
 *
 * Pegawai yang berhenti lalu bekerja lagi tidak menggandakan orangnya: satu
 * `hr_people`, banyak catatan kepegawaian (§17). Guru yang mengajar di dua unit
 * juga tidak digandakan: satu pegawai, banyak penugasan (migrasi berikutnya).
 *
 * ### Jembatan ke tabel `employees` lama
 *
 * Modul akademik, presensi, jurnal, aset, dan koperasi lama masih menunjuk
 * `employees.id`. Daripada memaksa migrasi data serentak, HRD menaut lewat
 * `legacy_employee_id` dan menuliskan proyeksi ringkasnya (nama, NIP, unit,
 * status, aktif/tidak) pada setiap perubahan. Dengan begitu:
 *
 *  - modul lama tetap bekerja tanpa diubah, dan
 *  - HRD tetap satu-satunya tempat data diubah.
 *
 * Jembatan ini dapat dimatikan (`hr.legacy_bridge.enabled`) pada instalasi baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_people', function (Blueprint $table): void {
            $table->id();
            $table->string('nik', 32)->nullable()->unique()->comment('Nomor induk kependudukan — unik bila diisi');
            $table->string('full_name', 180);
            $table->string('preferred_name', 80)->nullable();
            $table->string('gender', 20)->nullable()->comment('male | female | other');
            $table->string('birth_place', 120)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('marital_status', 30)->nullable()->comment('single | married | divorced | widowed');
            $table->string('religion', 60)->nullable();
            $table->string('nationality', 60)->nullable()->default('Indonesia');
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('personal_email', 150)->nullable();
            $table->string('work_email', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('country', 60)->default('Indonesia');
            $table->string('photo_path', 250)->nullable();
            $table->json('extra')->nullable()->comment('Atribut kependudukan tambahan yang belum dinormalisasi');
            $table->string('status', 20)->default('active')->comment('active | inactive | archived');
            $table->foreignId('merged_into_person_id')->nullable()->constrained('hr_people')->nullOnDelete()
                ->comment('Diisi bila orang ini hasil penggabungan duplikat');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('full_name');
            $table->index('mobile');
            $table->index('personal_email');
        });

        Schema::create('hr_employees', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_number', 40)->unique();
            $table->foreignId('person_id')->constrained('hr_people')->cascadeOnDelete();
            $table->foreignId('legacy_employee_id')->nullable()->unique()->constrained('employees')->nullOnDelete()
                ->comment('Tautan ke master pegawai lama agar modul akademik tetap bekerja');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Akun login — TIDAK dibuat otomatis (§67)');

            // Penempatan utama. Nilai ini adalah ringkasan dari penugasan utama
            // (hr_employee_assignments) dan diperbarui oleh mesin transaksi —
            // bukan diketik langsung di formulir pegawai.
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('hr_work_locations')->nullOnDelete();
            $table->foreignId('manager_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();

            $table->string('lifecycle_state', 30)->default('draft')
                ->comment('draft | preboarding | active | probation | confirmed | leave | suspended | offboarding | inactive | archived');
            $table->string('employment_status', 60)->default('DRAFT')
                ->comment('Status yang dilihat pengguna — kode referensi group employee_status, dapat diubah sekolah');
            $table->string('worker_type', 60)->nullable()->comment('Referensi group worker_type');
            $table->string('employment_type', 60)->nullable()->comment('Referensi group employment_type');

            $table->date('hire_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->date('confirmation_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('exit_reason_code', 60)->nullable()->comment('Referensi group exit_reason');
            $table->string('termination_notes', 500)->nullable();

            $table->boolean('is_active')->default(true)->comment('Dipakai modul lain sebagai penanda cepat');
            $table->dateTime('archived_at')->nullable();
            $table->string('archive_reason', 300)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Indeks yang benar-benar dipakai penyaring laporan (§76).
            $table->index(['lifecycle_state', 'is_active']);
            $table->index(['organization_id', 'lifecycle_state']);
            $table->index(['department_id', 'lifecycle_state']);
            $table->index(['position_id', 'lifecycle_state']);
            $table->index(['location_id', 'lifecycle_state']);
            $table->index('hire_date');
            $table->index('termination_date');
            $table->index('employment_status');
            $table->index('worker_type');
        });

        /*
         * Identitas tambahan: NPWP, BPJS Kesehatan/Ketenagakerjaan, NIP/NUPTK,
         * paspor, nomor rekening. Dipisah dari `hr_people` supaya jenis identitas
         * dapat ditambah sekolah tanpa menambah kolom, dan supaya nomor yang
         * sensitif dapat disamarkan per jenis (§52).
         */
        Schema::create('hr_employee_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('type_code', 40)->comment('nik | npwp | bpjs_kesehatan | bpjs_ketenagakerjaan | nuptk | nip | passport | bank_account | other');
            $table->string('number', 80);
            $table->string('label', 80)->nullable();
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->string('notes', 300)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['type_code', 'number'], 'hr_employee_identifiers_type_number_unique');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_identifiers');
        Schema::dropIfExists('hr_employees');
        Schema::dropIfExists('hr_people');
    }
};
