<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding, pre-boarding, dan offboarding (§38–§40).
 *
 * Pola yang dipakai sama untuk ketiga alur — dan sengaja berbeda dari "checklist
 * bebas":
 *
 * ```
 * template        apa yang harus terjadi (per worker type / posisi / unit)
 *       ↓
 * process         satu kejadian nyata untuk satu pegawai
 *       ↓
 * task            pekerjaan berpenanggung jawab, bertenggat, dan berstatus
 * ```
 *
 * Template dipilih otomatis saat proses dimulai (worker type → posisi → unit,
 * berurutan), sehingga guru kontrak di pesantren mendapat daftar tugas berbeda
 * dari staf kantor tanpa admin menyusun ulang setiap kali.
 *
 * Offboarding memakai struktur yang sama, dengan tambahan `clearance_area`
 * (aset, keuangan, dokumen, TI, perpustakaan): tugas clearance bukan sekadar
 * "selesai/tidak", melainkan "dibebaskan oleh siapa, kapan, dengan catatan apa"
 * — syarat sebelum pegawai benar-benar menjadi `inactive`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 180);
            $table->string('worker_type_code', 60)->nullable();
            $table->string('employment_type_code', 60)->nullable();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('hr_work_locations')->nullOnDelete();
            $table->unsignedSmallInteger('priority')->default(0)->comment('Template dengan priority tertinggi yang cocok akan dipakai');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 20)->default('active')->comment('active | inactive | archived');
            $table->string('description', 400)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['worker_type_code', 'status']);
            $table->index('priority');
        });

        Schema::create('hr_onboarding_template_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('hr_onboarding_templates')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('title', 200);
            $table->string('category', 40)->default('document')->comment('data | document | contract | verification | orientation | equipment | account | training | meeting | policy | other');
            $table->string('owner_type', 30)->default('employee')->comment('employee | manager | hr | it | finance | other');
            $table->string('owner_role', 60)->nullable()->comment('Peran penerima tugas bila owner bukan pegawai baru');
            $table->integer('due_offset_days')->default(0)->comment('Relatif terhadap tanggal mulai (negatif = sebelum hari pertama)');
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('blocks_activation')->default(false)->comment('Menahan perpindahan ke `active` sampai selesai');
            $table->json('depends_on')->nullable()->comment('Kode tugas yang harus selesai lebih dulu');
            $table->string('instructions', 1000)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['template_id', 'code']);
        });

        Schema::create('hr_onboarding_processes', function (Blueprint $table): void {
            $table->id();
            $table->string('process_number', 60)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('hr_onboarding_templates')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('hr_transactions')->nullOnDelete();
            $table->string('status', 30)->default('pending')->comment('pending | preboarding | in_progress | completed | cancelled');
            $table->boolean('is_preboarding')->default(true)->comment('Berjalan sebelum hari pertama');
            $table->date('start_date')->nullable()->comment('Hari pertama kerja');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedTinyInteger('progress')->default(0)->comment('Persentase tugas wajib yang selesai');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete()->comment('PIC HR');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'start_date']);
        });

        Schema::create('hr_onboarding_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('process_id')->constrained('hr_onboarding_processes')->cascadeOnDelete();
            $table->foreignId('template_task_id')->nullable()->constrained('hr_onboarding_template_tasks')->nullOnDelete();
            $table->string('code', 60);
            $table->string('title', 200);
            $table->string('category', 40)->default('document');
            $table->string('owner_type', 30)->default('employee');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('blocks_activation')->default(false);
            $table->string('status', 30)->default('pending')->comment('pending | in_progress | waiting_review | completed | waived | overdue');
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('document_id')->nullable()->comment('Rujukan lunak ke hr_employee_documents');
            $table->string('notes', 500)->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['process_id', 'status']);
            $table->index(['status', 'due_date']);
        });

        Schema::create('hr_offboarding_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 180);
            $table->string('worker_type_code', 60)->nullable();
            $table->string('exit_reason_code', 60)->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->string('status', 20)->default('active');
            $table->string('description', 400)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hr_offboarding_template_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('hr_offboarding_templates')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('title', 200);
            $table->string('category', 40)->default('clearance')->comment('notice | knowledge_transfer | clearance | asset_return | account_revocation | financial | document | interview | approval | other');
            $table->string('owner_type', 30)->default('hr');
            $table->string('owner_role', 60)->nullable();
            $table->string('clearance_area', 40)->nullable()->comment('asset | finance | document | it | library | academic | other');
            $table->boolean('requires_clearance')->default(false)->comment('Harus dibebaskan pejabat area, bukan cukup dicentang');
            $table->integer('due_offset_days')->default(0)->comment('Relatif terhadap tanggal berakhir');
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('blocks_exit')->default(false)->comment('Menahan status `inactive` sampai selesai');
            $table->boolean('requires_attachment')->default(false);
            $table->string('instructions', 1000)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['template_id', 'code']);
        });

        Schema::create('hr_offboarding_processes', function (Blueprint $table): void {
            $table->id();
            $table->string('process_number', 60)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('hr_offboarding_templates')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('hr_transactions')->nullOnDelete();
            $table->string('exit_reason_code', 60)->nullable();
            $table->string('status', 30)->default('draft')->comment('draft | submitted | approved | notice_period | in_progress | cleared | completed | cancelled');
            $table->date('notice_date')->nullable();
            $table->date('last_working_date')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->boolean('is_rehireable')->default(true);
            $table->string('exit_interview_notes', 2000)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'last_working_date']);
        });

        Schema::create('hr_offboarding_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('process_id')->constrained('hr_offboarding_processes')->cascadeOnDelete();
            $table->foreignId('template_task_id')->nullable()->constrained('hr_offboarding_template_tasks')->nullOnDelete();
            $table->string('code', 60);
            $table->string('title', 200);
            $table->string('category', 40)->default('clearance');
            $table->string('owner_type', 30)->default('hr');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('clearance_area', 40)->nullable();
            $table->boolean('requires_clearance')->default(false);
            $table->string('clearance_status', 30)->default('pending')->comment('pending | cleared | waived | rejected');
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cleared_at')->nullable();
            $table->string('clearance_notes', 500)->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('blocks_exit')->default(false);
            $table->boolean('requires_attachment')->default(false);
            $table->string('status', 30)->default('pending')->comment('pending | in_progress | waiting_review | completed | overdue | cancelled');
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['process_id', 'status']);
            $table->index(['clearance_area', 'clearance_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offboarding_tasks');
        Schema::dropIfExists('hr_offboarding_processes');
        Schema::dropIfExists('hr_offboarding_template_tasks');
        Schema::dropIfExists('hr_offboarding_templates');
        Schema::dropIfExists('hr_onboarding_tasks');
        Schema::dropIfExists('hr_onboarding_processes');
        Schema::dropIfExists('hr_onboarding_template_tasks');
        Schema::dropIfExists('hr_onboarding_templates');
    }
};
