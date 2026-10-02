<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posisi — kursi nyata di dalam organisasi (§8, §49, §50).
 *
 * Inilah pembeda HRIS dari buku besar pegawai biasa. `employees.position` (teks
 * bebas) tidak dapat menjawab tiga pertanyaan yang selalu ditanyakan manajemen:
 *
 *   1. Berapa kursi yang diizinkan, berapa yang terisi, berapa yang kosong?
 *   2. Siapa yang menduduki kursi ini pada 1 Januari lalu?
 *   3. Kursi ini melapor ke kursi mana?
 *
 * Karena itu `authorized_headcount` + `filled_headcount` hidup di posisi, dan
 * kepenuhannya dihitung ulang dari penugasan aktif — bukan diketik manual.
 * `vacancy_status` adalah hasil hitungan itu (`vacant` / `partially_filled` /
 * `fully_filled` / `frozen` / `closed`), jadi Recruitment fase berikutnya cukup
 * membaca posisi, bukan menghitung ulang.
 *
 * `reports_to_position_id` melapor ke POSISI, bukan ke orang: ketika pejabatnya
 * berganti, rantai pelaporan tidak perlu diperbaiki satu per satu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_positions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('title', 180);
            $table->foreignId('job_id')->nullable()->constrained('hr_jobs')->nullOnDelete();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('hr_work_locations')->nullOnDelete();
            $table->foreignId('parent_position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->foreignId('reports_to_position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->string('position_type', 40)->default('permanent')->comment('permanent | contract | temporary | acting | project | honorary | volunteer');
            $table->string('employment_type_code', 60)->nullable()->comment('Referensi group employment_type');
            $table->string('worker_type_code', 60)->nullable()->comment('Referensi group worker_type');
            $table->unsignedInteger('authorized_headcount')->default(1);
            $table->unsignedInteger('planned_headcount')->default(1);
            $table->unsignedInteger('filled_headcount')->default(0)->comment('Dihitung ulang dari penugasan aktif');
            $table->string('vacancy_status', 30)->default('vacant')->comment('vacant | partially_filled | fully_filled | frozen | closed');
            $table->string('grade_code', 40)->nullable()->comment('Rujukan hr_job_grades.code');
            $table->string('cost_center_code', 30)->nullable();
            $table->boolean('is_critical')->default(false)->comment('Kursi kritis (succession planning fase berikutnya)');
            $table->boolean('is_approved')->default(false);
            $table->string('status', 20)->default('active')->comment('draft | active | frozen | abolished | archived');
            $table->string('description', 500)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->date('target_fill_date')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['department_id', 'status']);
            $table->index(['vacancy_status', 'status']);
            $table->index('reports_to_position_id');
        });

        /*
         * Riwayat posisi: perubahan headcount, pembekuan, pemansuhan, dan
         * perubahan atasan posisi. Tanpa ini, "posisi ini dulu dibekukan kapan?"
         * hanya bisa dijawab dengan menebak dari audit log umum.
         */
        Schema::create('hr_position_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('position_id')->constrained('hr_positions')->cascadeOnDelete();
            $table->string('change_type', 40);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->date('effective_from')->nullable();
            $table->string('reason', 300)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['position_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_position_histories');
        Schema::dropIfExists('hr_positions');
    }
};
