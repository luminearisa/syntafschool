<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penugasan dan hubungan atasan (§19–§21).
 *
 * Tabel ini yang membuat HRD dapat menjawab dua pertanyaan yang tidak terjawab
 * oleh kolom `employee.position`:
 *
 *  1. **"Siapa posisi Ahmad pada 1 Juli 2025?"** — penugasan punya
 *     `start_date`/`end_date`; yang lama TIDAK ditimpa, hanya ditutup.
 *  2. **"Ahmad sebenarnya mengerjakan apa?"** — satu pegawai dapat punya
 *     penugasan utama (Guru Matematika), penugasan kedua (Pembina OSIS), dan
 *     penugasan sementara (Plt. Kepala Unit selama 3 bulan).
 *
 * `is_primary` hanya boleh satu per pegawai pada satu waktu. Itu TIDAK dipaksakan
 * lewat unique index parsial (MySQL tidak mendukungnya) melainkan oleh
 * `AssignmentService` di dalam transaksi basis data dengan `lockForUpdate`, dan
 * dilaporkan sebagai temuan kualitas data `hr.assignment.multiple_primary` bila
 * data lama sudah terlanjur melanggar.
 *
 * Hubungan atasan dipisah dari penugasan karena satu pegawai dapat punya atasan
 * langsung, atasan fungsional, dan atasan garis putus-putus sekaligus (§21) —
 * sesuatu yang tidak bisa diwakili satu kolom `manager_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('hr_work_locations')->nullOnDelete();
            $table->foreignId('manager_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('assignment_type', 30)->default('primary')->comment('primary | secondary | temporary | acting | project');
            $table->boolean('is_primary')->default(false);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('percentage', 5, 2)->nullable()->comment('Porsi waktu/penugasan, mis. 50.00');
            $table->string('status', 20)->default('active')->comment('draft | active | ended | cancelled');
            $table->foreignId('transaction_id')->nullable()->comment('Transaksi HR yang melahirkannya');
            $table->string('reference_number', 60)->nullable()->comment('Mis. nomor SK');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['employee_id', 'is_primary']);
            $table->index(['position_id', 'status']);
            $table->index(['organization_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('hr_employee_managers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('manager_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('relationship_type', 30)->default('direct')
                ->comment('direct | functional | dotted_line | acting | primary');
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active')->comment('active | ended | cancelled');
            $table->foreignId('transaction_id')->nullable()->comment('Transaksi HR yang melahirkannya');
            $table->string('notes', 300)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['manager_employee_id', 'status']);
            $table->index(['relationship_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_managers');
        Schema::dropIfExists('hr_employee_assignments');
    }
};
