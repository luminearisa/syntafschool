<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesin transaksi SDM, riwayat status, dan linimasa (§33–§35, §68, §69, §77).
 *
 * Prinsipnya satu: **data pegawai tidak pernah diubah langsung oleh perubahan
 * organisasi.** Transfer, promosi, demosi, mutasi, perubahan status, dan
 * pemutusan hubungan kerja semuanya berbentuk *transaksi* yang punya status,
 * persetujuan, tanggal berlaku, dan jejak sebelum/sesudah.
 *
 * Alur satu transfer yang benar:
 *
 * ```
 * hr_transactions (submitted)
 *        ↓  workflow engine memutuskan
 *        ↓  (approved, effective_date = 1 Juli)
 * apply() dalam SATU transaksi basis data:
 *        ↓  tutup penugasan lama   (end_date, status=ended)
 *        ↓  buat penugasan baru    (assignment baru + is_primary)
 *        ↓  perbarui ringkasan pegawai (organization/department/position/manager)
 *        ↓  catat status history + timeline + audit
 *        ↓  kirim notifikasi + outbox integrasi
 * ```
 *
 * `idempotency_key` mencegah satu permintaan klien diproses dua kali ketika
 * jaringan putus dan permintaan dikirim ulang (§78). `version` pada pegawai &
 * penugasan adalah kunci optimistis: dua HR yang menyunting pegawai sama tidak
 * saling menimpa tanpa tahu.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Jenis transaksi = konfigurasi, bukan `switch` di kode. Setiap jenis
         * menunjuk "handler efek" (`effect_handler`) yang mengeksekusi
         * perubahannya dan kode definisi workflow untuk persetujuannya.
         */
        Schema::create('hr_transaction_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique()->comment('TRANSFER | PROMOTION | DEMOTION | MUTATION | STATUS_CHANGE | TERMINATION | ...');
            $table->string('name', 150);
            $table->string('category', 40)->default('assignment')->comment('hire | assignment | employment | status | contract | offboarding | other');
            $table->string('effect_handler', 60)->comment('Alias handler efek: transfer | promotion | demotion | mutation | status_change | hire | termination | resignation | retirement | rehire | contract_extension | contract_renewal | assignment | manager_change | position_change');
            $table->boolean('requires_approval')->default(true);
            $table->string('workflow_definition_code', 80)->nullable()->comment('Kode definisi pada Workflow Engine');
            $table->boolean('requires_effective_date')->default(true);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('requires_reason')->default(true);
            $table->json('applies_to')->nullable()->comment('Batasan worker type/employment type');
            $table->json('allowed_from_states')->nullable()->comment('Keadaan lifecycle yang mengizinkan transaksi ini');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->string('description', 400)->nullable();
            $table->timestamps();

            $table->index(['category', 'status']);
        });

        Schema::create('hr_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_number', 60)->unique();
            $table->string('type_code', 60);
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('status', 30)->default('draft')
                ->comment('draft | submitted | review | approved | rejected | cancelled | completed');
            $table->date('effective_date')->nullable();
            $table->string('reason_code', 60)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('priority', 20)->default('normal')->comment('low | normal | high | urgent');
            $table->json('payload')->nullable()->comment('Nilai yang diminta, apa adanya');
            $table->json('before_snapshot')->nullable()->comment('Keadaan pegawai saat pengajuan');
            $table->json('after_snapshot')->nullable()->comment('Keadaan pegawai setelah diterapkan');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->string('decision_notes', 500)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('workflow_instance_id')->nullable()->comment('Rujukan lunak ke workflow_instances');
            $table->string('workflow_status', 30)->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->unsignedInteger('version')->default(1);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['type_code', 'status']);
            $table->index(['status', 'effective_date']);
            $table->index('submitted_at');
        });

        /*
         * Rincian perubahan per baris: apa yang berubah, dari nilai apa ke nilai
         * apa. Inilah yang membuat layar persetujuan dapat menampilkan
         * "Departemen: Finance → Academic" alih-alih hanya "setuju/tolak".
         */
        Schema::create('hr_transaction_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained('hr_transactions')->cascadeOnDelete();
            $table->string('item_type', 40)->comment('assignment | employment | status | contract | position | manager | organization | department | location | grade | other');
            $table->string('action', 30)->default('update')->comment('create | update | close | cancel');
            $table->string('entity_type', 80)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('field', 80)->nullable();
            $table->json('before_value')->nullable();
            $table->json('after_value')->nullable();
            $table->string('label', 200)->nullable()->comment('Teks yang ditampilkan di layar persetujuan');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['transaction_id', 'item_type']);
        });

        Schema::create('hr_employee_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('from_status', 60)->nullable();
            $table->string('to_status', 60);
            $table->string('lifecycle_from', 30)->nullable();
            $table->string('lifecycle_to', 30);
            $table->date('effective_date')->nullable();
            $table->string('reason', 400)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('hr_transactions')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
        });

        /*
         * Linimasa pegawai: satu tempat melihat seluruh riwayat (§24). Baris di
         * sini DITURUNKAN dari peristiwa domain — bukan diketik manual — dan
         * `dedupe_key` mencegah peristiwa yang sama tercatat dua kali ketika
         * listener dijalankan ulang (retry queue).
         */
        Schema::create('hr_employee_timeline_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('event_code', 80)->comment('employee.created | employee.transferred | certification.added | ...');
            $table->string('category', 40)->default('lifecycle')->comment('lifecycle | employment | assignment | document | skill | certification | education | request | system');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->dateTime('occurred_at');
            $table->date('effective_date')->nullable();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('visibility', 20)->default('hr')->comment('private | hr | manager | employee | public');
            $table->string('icon', 40)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dedupe_key', 120)->nullable()->unique();
            $table->timestamps();

            $table->index(['employee_id', 'occurred_at']);
            $table->index(['event_code', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });

        /*
         * Penggabungan pegawai duplikat (§58). Data historis TIDAK dihapus:
         * transaksi, penugasan, dokumen, dan linimasa dipindahkan ke pegawai
         * induk, sementara pegawai sumber ditandai `merged` dan tetap dapat
         * ditelusuri.
         */
        Schema::create('hr_employee_merges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('target_employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('status', 30)->default('draft')->comment('draft | submitted | approved | merged | rejected | cancelled');
            $table->json('comparison')->nullable()->comment('Perbandingan kolom per kolom untuk ditinjau manusia');
            $table->json('resolution')->nullable()->comment('Pilihan nilai yang dipertahankan untuk setiap kolom berbeda');
            $table->json('moved_records')->nullable()->comment('Ringkasan baris yang dipindahkan');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('merged_at')->nullable();
            $table->string('reason', 400)->nullable();
            $table->timestamps();

            $table->index(['source_employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_merges');
        Schema::dropIfExists('hr_employee_timeline_events');
        Schema::dropIfExists('hr_employee_status_histories');
        Schema::dropIfExists('hr_transaction_items');
        Schema::dropIfExists('hr_transactions');
        Schema::dropIfExists('hr_transaction_types');
    }
};
