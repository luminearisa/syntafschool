<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisasi berjenjang (§3–§7, §75).
 *
 * Satu pohon untuk seluruh entitas: Yayasan → Sekolah/Pesantren → Unit → Divisi →
 * Departemen → Seksi. Tidak ada kolom `company`/`unit` yang dipaku; yang ada
 * adalah `parent_id` + `type_code` (dari referensi, bukan enum di kode).
 *
 * Dua hal yang sengaja TIDAK dilakukan di sini:
 *
 *  1. Tidak menyalin data `foundations`/`schools` yang sudah ada. Organisasi HRD
 *     hanya *menaut* ke sana (`foundation_id`, `school_id`) sehingga master
 *     institusi tetap satu sumber.
 *  2. Tidak memasang foreign key database untuk `manager_employee_id` /
 *     `head_employee_id`: `hr_employees` dibuat setelah tabel ini (rujukan
 *     melingkar). Relasi itu dijaga di lapisan layanan + pemeriksa kualitas data
 *     `hr.employee.manager.orphan`, dan didokumentasikan sebagai rujukan lunak.
 *
 * `path` + `depth` menyimpan materialized path supaya sub-pohon dapat dibaca
 * dengan satu kueri `LIKE 'path%'` — bagan organisasi besar tidak menjadi ratusan
 * kueri rekursif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 180);
            $table->string('type_code', 60)->comment('Referensi group organization_type');
            $table->foreignId('parent_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained('hr_organizations')->nullOnDelete()
                ->comment('Badan hukum yang menaungi unit ini');
            $table->foreignId('foundation_id')->nullable()->constrained('foundations')->nullOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->unsignedBigInteger('manager_employee_id')->nullable()->comment('Rujukan lunak ke hr_employees');
            $table->string('cost_center_code', 30)->nullable()->comment('Rujukan lunak ke finance_cost_centers.code');
            $table->string('path', 255)->nullable()->comment('Materialized path, mis. 1/4/9/');
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('status', 20)->default('active')->comment('active | inactive | archived');
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('authorized_headcount')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1)->comment('Optimistic lock');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'status']);
            $table->index(['type_code', 'status']);
            $table->index('path');
            $table->index('manager_employee_id');
            $table->index(['status', 'effective_from']);
        });

        // Lokasi kerja dibuat lebih dulu: departemen menaut ke lokasi, bukan
        // sebaliknya — urutan ini menjaga foreign key tetap nyata (bukan rujukan
        // lunak) pada MySQL maupun SQLite.
        Schema::create('hr_work_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 180);
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->string('location_type', 40)->default('office')->comment('campus | school | office | branch | remote | hybrid | field | boarding | other');
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('country', 60)->default('Indonesia');
            $table->string('postal_code', 10)->nullable();
            $table->string('timezone', 60)->default('Asia/Jakarta');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('allows_remote')->default(false);
            $table->boolean('is_primary')->default(false)->comment('Lokasi utama unit organisasi');
            $table->string('status', 20)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index('location_type');
        });

        Schema::create('hr_departments', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 180);
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            // Nullable organization link for cross-unit departments is rejected in the service.
            $table->unsignedBigInteger('head_employee_id')->nullable()->comment('Rujukan lunak ke hr_employees');
            $table->foreignId('location_id')->nullable()->constrained('hr_work_locations')->nullOnDelete();
            $table->string('cost_center_code', 30)->nullable();
            $table->string('budget_reference', 60)->nullable();
            $table->unsignedInteger('authorized_headcount')->nullable();
            $table->string('status', 20)->default('active')->comment('active | inactive | archived');
            $table->string('description', 500)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index('head_employee_id');
        });

        /*
         * Riwayat perubahan struktur. Mengubah induk unit bukan sekadar UPDATE:
         * siapa pindah ke mana, kapan berlaku, dan atas alasan apa harus dapat
         * ditelusuri tanpa harus membedah audit log umum.
         */
        Schema::create('hr_organization_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->string('change_type', 40)->comment('created | renamed | reparented | typed | deactivated | activated | archived | manager_changed | headcount_changed');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->date('effective_from')->nullable();
            $table->string('reason', 300)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        /*
         * Penomoran dokumen HR (employee number, nomor transaksi, kontrak,
         * permintaan). Pola per baris supaya tiap unit dapat memakai nomor
         * berbeda — "GURU-0001" untuk unit pesantren, "EMP-2026-0001" untuk
         * kantor yayasan (§14).
         */
        Schema::create('hr_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 40)->comment('employee | transaction | request | contract | onboarding | offboarding | merge');
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('prefix', 20)->nullable();
            $table->string('pattern', 80)->nullable()->comment('Mis. EMP-{TAHUN}-{URUT}');
            $table->unsignedTinyInteger('padding')->default(4);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->dateTime('last_issued_at')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'organization_id', 'year', 'prefix'], 'hr_number_sequences_scope_unique');
        });

        /*
         * Setelan modul: nilai bawaan `config/hrd.php`, dapat ditimpa per unit
         * organisasi tanpa mengubah kode.
         */
        Schema::create('hr_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100);
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->cascadeOnDelete();
            $table->json('value')->nullable();
            $table->string('description', 250)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_settings');
        Schema::dropIfExists('hr_number_sequences');
        Schema::dropIfExists('hr_organization_histories');
        Schema::dropIfExists('hr_work_locations');
        Schema::dropIfExists('hr_departments');
        Schema::dropIfExists('hr_organizations');
    }
};
