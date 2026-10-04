<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Procurement & Vendor — Fase 3: RFQ, penawaran (quotation), dokumen
 * penawaran, kriteria & hasil evaluasi, serta pemilihan vendor (§12–§16).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- RFQ ----------
        Schema::create('proc_rfqs', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('title', 180);
            $table->foreignId('event_id')->nullable()->constrained('proc_events')->nullOnDelete();
            $table->dateTime('deadline')->nullable();
            $table->string('validity_requirement', 120)->nullable();
            $table->string('delivery_requirement', 180)->nullable();
            $table->string('delivery_location', 180)->nullable();
            $table->string('payment_terms', 60)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('DRAFT');
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'deadline']);
        });

        Schema::create('proc_rfq_vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('proc_rfqs')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('status', 20)->default('INVITED'); // INVITED | RESPONDED | DECLINED
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'vendor_id'], 'proc_rfq_vendor_unique');
        });

        Schema::create('proc_rfq_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('proc_rfqs')->cascadeOnDelete();
            $table->foreignId('purchase_request_item_id')->nullable()->constrained('proc_purchase_request_items')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('proc_items')->nullOnDelete();
            $table->string('description', 255);
            $table->string('specification', 255)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->string('unit', 30)->default('unit');
            $table->decimal('target_price', 18, 2)->nullable();
            $table->date('required_date')->nullable();
            $table->timestamps();

            $table->index('rfq_id');
        });

        // ---------- Penawaran vendor (snapshot) ----------
        Schema::create('proc_quotations', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('rfq_id')->nullable()->constrained('proc_rfqs')->nullOnDelete();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('proc_events')->nullOnDelete();
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->string('payment_terms', 60)->nullable();
            $table->string('delivery_terms', 120)->nullable();
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->decimal('shipping_cost', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 20)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'status']);
            $table->index('valid_until');
        });

        Schema::create('proc_quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained('proc_quotations')->cascadeOnDelete();
            $table->foreignId('rfq_item_id')->nullable()->constrained('proc_rfq_items')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('proc_items')->nullOnDelete();
            $table->string('description', 255);
            $table->string('specification', 255)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->string('unit', 30)->default('unit');
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->string('delivery_estimate', 80)->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->timestamps();

            $table->index('quotation_id');
        });

        Schema::create('proc_quotation_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained('proc_quotations')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('file_path', 255);
            $table->timestamps();
        });

        // ---------- Kriteria & evaluasi penawaran ----------
        Schema::create('proc_evaluation_criteria', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->nullable()->constrained('proc_rfqs')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->decimal('weight', 6, 2)->default(0); // persen
            $table->decimal('max_score', 6, 2)->default(100);
            $table->string('type', 16)->default('NUMERIC'); // NUMERIC | YESNO | PASSFAIL
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('rfq_id');
        });

        Schema::create('proc_quotation_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->nullable()->constrained('proc_quotations')->cascadeOnDelete();
            $table->foreignId('rfq_id')->nullable()->constrained('proc_rfqs')->cascadeOnDelete();
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('DRAFT');
            $table->decimal('total_score', 8, 2)->default(0);
            $table->decimal('weighted_score', 8, 2)->default(0);
            $table->string('recommendation', 255)->nullable();
            $table->text('comments')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->index(['quotation_id', 'evaluator_id']);
        });

        Schema::create('proc_quotation_evaluation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('proc_quotation_evaluations')->cascadeOnDelete();
            $table->foreignId('criteria_id')->nullable()->constrained('proc_evaluation_criteria')->nullOnDelete();
            $table->string('criterion_code', 40)->nullable();
            $table->string('criterion_name', 120)->nullable();
            $table->decimal('weight', 6, 2)->default(0);
            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('max_score', 8, 2)->default(100);
            $table->string('result', 16)->nullable(); // PASS | FAIL | YES | NO
            $table->string('comment', 500)->nullable();
            $table->string('evidence_path', 255)->nullable();
            $table->timestamps();
        });

        // ---------- Pemilihan vendor ----------
        Schema::create('proc_vendor_selections', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('event_id')->nullable()->constrained('proc_events')->nullOnDelete();
            $table->foreignId('rfq_id')->nullable()->constrained('proc_rfqs')->nullOnDelete();
            $table->foreignId('selected_quotation_id')->constrained('proc_quotations')->cascadeOnDelete();
            $table->foreignId('selected_vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->foreignId('alternative_vendor_id')->nullable()->constrained('proc_vendors')->nullOnDelete();
            $table->text('rationale');
            $table->string('recommendation', 255)->nullable();
            $table->json('rejection_summary')->nullable();
            $table->string('status', 20)->default('DRAFT'); // DRAFT | APPROVED | REJECTED
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proc_vendor_selections');
        Schema::dropIfExists('proc_quotation_evaluation_items');
        Schema::dropIfExists('proc_quotation_evaluations');
        Schema::dropIfExists('proc_evaluation_criteria');
        Schema::dropIfExists('proc_quotation_documents');
        Schema::dropIfExists('proc_quotation_items');
        Schema::dropIfExists('proc_quotations');
        Schema::dropIfExists('proc_rfq_items');
        Schema::dropIfExists('proc_rfq_vendors');
        Schema::dropIfExists('proc_rfqs');
    }
};
