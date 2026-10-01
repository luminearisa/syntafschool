<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penghapusan aset (permintaan → inspeksi → valuasi → persetujuan → pelaksanaan) dan usulan penggantian.
 *
 * `asset_replacements` menyimpan USULAN berdasarkan aturan yang dapat dikonfigurasi;
 * ia tidak pernah membuat pesanan pembelian sendiri — keputusan tetap milik manusia,
 * dan pengadaannya milik modul Pengadaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_disposal_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('request_number', 40)->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('reason', 32);
            $table->string('method', 32)->nullable();
            $table->string('status', 24)->default('DRAFT');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('condition_at_request', 24)->nullable();
            $table->string('book_value_at_request', 32)->nullable();
            $table->decimal('estimated_value', 16, 2)->nullable();
            $table->decimal('expected_proceeds', 16, 2)->nullable();
            $table->unsignedBigInteger('inspection_id')->nullable();
            $table->unsignedBigInteger('valuation_id')->nullable();
            $table->string('buyer_name', 160)->nullable();
            $table->string('donation_recipient', 160)->nullable();
            $table->string('approval_reference', 80)->nullable()->comment('Rujukan proses persetujuan pada Workflow Engine');
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('executed_at')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('finance_reference', 80)->nullable();
            $table->string('finance_status', 24)->default('PENDING');
            $table->dateTime('retired_at')->nullable();
            $table->string('document_reference', 200)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'reason']);
        });
        Schema::create('asset_disposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('disposal_request_id')->constrained('asset_disposal_requests')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('method', 32);
            $table->decimal('proceeds', 16, 2)->default(0);
            $table->string('recipient', 160)->nullable();
            $table->foreignId('executed_by')->constrained('users')->cascadeOnDelete();
            $table->dateTime('executed_at');
            $table->string('finance_reference', 80)->nullable();
            $table->string('document_reference', 200)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('asset_replacements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('recommendation', 24);
            $table->json('trigger_reasons')->nullable();
            $table->decimal('age_ratio', 6, 3)->nullable();
            $table->decimal('maintenance_cost_ratio', 6, 3)->nullable();
            $table->unsignedInteger('downtime_days')->nullable();
            $table->string('priority', 24)->default('NORMAL');
            $table->string('status', 24)->default('OPEN')->comment('OPEN | REVIEWED | APPROVED | REJECTED | CONVERTED');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->string('procurement_reference', 80)->nullable();
            $table->foreignId('replacement_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->dateTime('generated_at');
            $table->timestamps();
            $table->unique(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_replacements');
        Schema::dropIfExists('asset_disposals');
        Schema::dropIfExists('asset_disposal_requests');
    }
};
