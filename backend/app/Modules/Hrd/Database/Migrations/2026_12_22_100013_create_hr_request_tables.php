<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan HR dan permintaan perubahan data (§45, §46).
 *
 * Satu tabel untuk seluruh permintaan pegawai, dengan jenis sebagai konfigurasi.
 * Yang penting bukan tabelnya, melainkan nilai **sebelum → diminta → disetujui →
 * diterapkan**: pegawai tidak boleh menyunting master data secara langsung, tetapi
 * HR juga tidak boleh menyuntingnya tanpa jejak.
 *
 * `hr_request_types.editable_fields` adalah daftar putih kolom yang boleh
 * diminta lewat jenis permintaan itu. Ini menutup celah paling umum pada modul
 * self-service: pegawai mengirim perubahan ke kolom yang ternyata sensitif
 * (mis. `employee_number` atau status kepegawaian) hanya karena formulirnya
 * menerima apa saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_request_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 180);
            $table->string('category', 40)->default('data_change')
                ->comment('data_change | letter | document | assignment_request | verification | other');
            $table->boolean('requires_approval')->default(true);
            $table->string('workflow_definition_code', 80)->nullable();
            $table->json('editable_fields')->nullable()->comment('Daftar putih kolom yang boleh diminta');
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('auto_apply_on_approval')->default(true)->comment('Diterapkan otomatis setelah disetujui, atau menunggu HR');
            $table->unsignedSmallInteger('sla_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('description', 400)->nullable();
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        Schema::create('hr_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_number', 60)->unique();
            $table->string('type_code', 60);
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('subject', 200)->nullable();
            $table->string('status', 30)->default('draft')
                ->comment('draft | submitted | review | approved | rejected | cancelled | completed');
            $table->json('current_values')->nullable();
            $table->json('requested_values')->nullable();
            $table->json('applied_values')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('evidence_document_id')->nullable()->comment('Rujukan lunak ke hr_employee_documents');
            $table->string('priority', 20)->default('normal');
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_notes', 500)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('workflow_instance_id')->nullable();
            $table->string('workflow_status', 30)->nullable();
            $table->dateTime('sla_due_at')->nullable();
            $table->dateTime('sla_breached_at')->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->unsignedInteger('version')->default(1);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['type_code', 'status']);
            $table->index(['status', 'sla_due_at']);
        });

        Schema::create('hr_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('hr_requests')->cascadeOnDelete();
            $table->string('field', 80);
            $table->string('label', 200)->nullable();
            $table->json('current_value')->nullable();
            $table->json('requested_value')->nullable();
            $table->json('approved_value')->nullable();
            $table->string('status', 30)->default('pending')->comment('pending | approved | rejected | applied');
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_request_items');
        Schema::dropIfExists('hr_requests');
        Schema::dropIfExists('hr_request_types');
    }
};
