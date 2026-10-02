<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referensi yang HARUS dapat diubah sekolah, bukan dipaku di kode (§18, §41, §87).
 *
 * Jenis organisasi, worker type, employment type, status kepegawaian, alasan
 * keluar, hubungan keluarga, dan jenjang pendidikan semuanya hidup di dua tabel
 * ini — bukan sebagai `enum` di kode. Konsekuensinya:
 *
 *  - sekolah dapat menambah "Guru Kontrak Yayasan" tanpa deploy ulang;
 *  - baris historis menyimpan `code`, sehingga mengubah label tidak mengubah masa
 *    lalu (laporan lama tetap terbaca apa adanya);
 *  - modul lain cukup membaca satu kontrak (`ReferenceProviderInterface`).
 *
 * `is_system = true` menandai baris yang dipakai mesin (mis. status `ACTIVE` pada
 * state machine lifecycle) — boleh diubah labelnya, tidak boleh dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_reference_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique()->comment('organization_type | worker_type | employment_type | employee_status | ...');
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->boolean('is_system')->default(false)->comment('Dipakai mesin; label boleh diubah, baris tidak boleh dihapus');
            $table->string('status', 20)->default('active')->comment('active | inactive');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('hr_reference_items', function (Blueprint $table): void {
            $table->id();
            $table->string('group_code', 60);
            $table->string('code', 60);
            $table->string('label', 150);
            $table->string('description', 300)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_default')->default(false)->comment('Nilai bawaan saat formulir dibuka');
            $table->json('meta')->nullable()->comment('Atribut tambahan per jenis, mis. {"requires_contract":true}');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['group_code', 'code']);
            $table->index(['group_code', 'is_active', 'sort_order']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('hr_reference_items');
        Schema::dropIfExists('hr_reference_groups');
    }
};
