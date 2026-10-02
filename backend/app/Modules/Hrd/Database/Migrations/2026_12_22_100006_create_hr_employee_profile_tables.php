<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil pegawai: kontak, alamat, kontak darurat, tanggungan, pendidikan
 * (§25–§28).
 *
 * Semuanya tabel anak — bukan kolom di `hr_employees`, dan bukan satu kolom JSON
 * besar. Alasannya praktis: satu orang dapat punya banyak alamat, banyak kontak,
 * banyak riwayat pendidikan, dan ketika Benefits/BPJS masuk pada fase berikutnya,
 * daftar tanggungan harus dapat dikueri ("siapa saja anak pegawai yang masuk
 * kelas 1?") — hal yang mustahil bila semuanya terkubur dalam satu kolom JSON.
 *
 * `verified` + `verified_by` + `verified_at` ada di pendidikan dan tanggungan
 * karena dokumen ini yang biasanya dipalsukan saat melamar kerja; HR perlu
 * menandai mana yang sudah diperiksa terhadap berkas aslinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('contact_type', 40)->comment('phone | mobile | work_email | personal_email | whatsapp | emergency_phone | other');
            $table->string('value', 180);
            $table->string('label', 80)->nullable()->comment('Mis. "Nomor kantor SMP"');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->string('notes', 300)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'contact_type']);
        });

        Schema::create('hr_employee_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('address_type', 40)->default('primary')->comment('primary | secondary | temporary | ktp | domicile | boarding | other');
            $table->text('address');
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('country', 60)->default('Indonesia');
            $table->boolean('is_primary')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'address_type']);
        });

        Schema::create('hr_emergency_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('name', 180);
            $table->string('relationship_code', 40)->comment('Referensi group relationship_type');
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedTinyInteger('priority')->default(1);
            $table->string('notes', 300)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'is_primary']);
        });

        Schema::create('hr_employee_dependents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('name', 180);
            $table->string('relationship_code', 40)->comment('spouse | child | parent | sibling | other');
            $table->string('gender', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nik', 32)->nullable();
            $table->string('education_level', 40)->nullable()->comment('Referensi group education_level');
            $table->string('occupation', 120)->nullable();
            $table->string('dependent_status', 30)->default('active')->comment('active | inactive | deceased');
            $table->boolean('is_beneficiary')->default(false)->comment('Penerima manfaat asuransi/beasiswa');
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->string('notes', 300)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'relationship_code']);
            $table->index('nik');
        });

        Schema::create('hr_employee_education', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('institution', 180);
            $table->string('level_code', 40)->comment('Referensi group education_level');
            $table->string('degree', 120)->nullable();
            $table->string('major', 150)->nullable();
            $table->unsignedSmallInteger('start_year')->nullable();
            $table->unsignedSmallInteger('end_year')->nullable();
            $table->decimal('gpa', 4, 2)->nullable();
            $table->string('certificate_number', 80)->nullable();
            $table->unsignedBigInteger('document_id')->nullable()->comment('Rujukan lunak ke hr_employee_documents');
            $table->boolean('is_highest')->default(false);
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->string('notes', 300)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'level_code']);
            $table->index('verified');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_education');
        Schema::dropIfExists('hr_employee_dependents');
        Schema::dropIfExists('hr_emergency_contacts');
        Schema::dropIfExists('hr_employee_addresses');
        Schema::dropIfExists('hr_employee_contacts');
    }
};
