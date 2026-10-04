<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Procurement & Vendor — Fase 6: invoice vendor, item invoice, hasil
 * 3-way matching, pengecualian (exception), dan catatan integrasi (handoff)
 * ke modul Accounting/Budget/Inventory/Asset (§28–§34, §50).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proc_vendor_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();            // nomor internal
            $table->string('vendor_invoice_number', 80);       // nomor dari vendor
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained('proc_purchase_orders')->nullOnDelete();
            $table->string('currency', 3)->default('IDR');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('shipping_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0); // cermin dari Accounting (bukan ledger)
            $table->string('status', 24)->default('DRAFT');
            $table->string('match_status', 20)->nullable();
            $table->string('source', 16)->default('MANUAL');    // MANUAL | PORTAL | API | UPLOAD | OCR
            $table->string('attachment_path', 255)->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('proc_vendor_invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'vendor_invoice_number'], 'proc_invoice_vendor_number_idx');
            $table->index(['status', 'match_status']);
            $table->index('due_date');
        });

        Schema::create('proc_vendor_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_invoice_id')->constrained('proc_vendor_invoices')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('proc_purchase_order_items')->nullOnDelete();
            $table->foreignId('goods_receipt_item_id')->nullable()->constrained('proc_goods_receipt_items')->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 18, 4)->default(0);
            $table->string('unit', 30)->default('unit');
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->timestamps();

            $table->index('vendor_invoice_id');
        });

        Schema::create('proc_invoice_match_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_invoice_id')->constrained('proc_vendor_invoices')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('proc_purchase_orders')->nullOnDelete();
            $table->string('match_status', 20);
            $table->decimal('quantity_variance', 18, 4)->default(0);
            $table->decimal('price_variance', 18, 4)->default(0);
            $table->decimal('amount_variance', 18, 2)->default(0);
            $table->json('tolerance_snapshot')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('vendor_invoice_id');
        });

        Schema::create('proc_invoice_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_invoice_id')->constrained('proc_vendor_invoices')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 20)->default('DETECTED');
            $table->string('severity', 16)->default('MEDIUM'); // LOW | MEDIUM | HIGH
            $table->text('description')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('investigation_notes')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('override_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['vendor_invoice_id', 'status']);
            $table->index('type');
        });

        /*
        | Catatan integrasi (outbox/inbox) untuk seluruh pemanggilan provider.
        | `idempotency_key` unik menjamin retry tidak menghasilkan posting ganda
        | (§33, §63). Bila modul tujuan belum terpasang, baris tetap tercatat
        | dengan status PENDING sehingga tidak hilang.
        */
        Schema::create('proc_integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            $table->string('provider', 24);     // ACCOUNTING | BUDGET | INVENTORY | ASSET | WORKFLOW | NOTIFICATION
            $table->string('direction', 12)->default('OUT'); // OUT | IN
            $table->string('reference', 120)->nullable();
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->string('external_id', 120)->nullable();
            $table->string('status', 24)->default('PENDING');
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proc_integrations');
        Schema::dropIfExists('proc_invoice_exceptions');
        Schema::dropIfExists('proc_invoice_match_results');
        Schema::dropIfExists('proc_vendor_invoice_items');
        Schema::dropIfExists('proc_vendor_invoices');
    }
};
