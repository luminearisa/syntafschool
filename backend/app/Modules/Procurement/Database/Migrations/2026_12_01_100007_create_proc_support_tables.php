<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Procurement & Vendor — Fase 7 (tabel pendukung): kontrak vendor,
 * item kontrak, cermin anggaran, komitmen anggaran, log audit, log peristiwa
 * domain, dan pengaturan modul (§35–§36, §50–§51).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proc_contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->string('title', 180);
            $table->string('type', 24)->default('CONTRACT'); // CONTRACT | FRAMEWORK | SLA | PRICING
            $table->foreignId('category_id')->nullable()->constrained('proc_categories')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('renewal_date')->nullable();
            $table->string('payment_terms', 60)->nullable();
            $table->string('volume_commitment', 120)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->decimal('value', 18, 2)->nullable();
            $table->string('status', 24)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'status']);
            $table->index('end_date');
        });

        Schema::create('proc_contract_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('proc_contracts')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('proc_items')->nullOnDelete();
            $table->string('description', 255);
            $table->string('unit', 30)->default('unit');
            $table->decimal('agreed_price', 18, 2)->default(0);
            $table->decimal('min_quantity', 18, 4)->nullable();
            $table->decimal('max_quantity', 18, 4)->nullable();
            $table->timestamps();

            $table->index('contract_id');
        });

        /*
        | Cermin anggaran (bukan sumber kebenaran). Nilai `amount/committed/actual`
        | disinkronkan dari BudgetProvider; kolom `source` menandai asalnya.
        */
        Schema::create('proc_budgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('fiscal_year', 9);
            $table->string('account_code', 40)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('committed', 18, 2)->default(0);
            $table->decimal('actual', 18, 2)->default(0);
            $table->string('source', 20)->default('ACCOUNTING');
            $table->timestamp('synced_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['fiscal_year', 'cost_center_id']);
        });

        Schema::create('proc_commitments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->nullable()->constrained('proc_budgets')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('proc_purchase_orders')->nullOnDelete();
            $table->foreignId('purchase_request_id')->nullable()->constrained('proc_purchase_requests')->nullOnDelete();
            $table->string('reference', 120)->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('status', 20)->default('RESERVED');
            $table->timestamp('released_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'status']);
        });

        Schema::create('proc_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 24);
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('action');
            $table->index('created_at');
        });

        /*
        | Log peristiwa domain (outbox) — jaminan bahwa peristiwa seperti
        | PurchaseRequestApproved atau InvoiceMatched tercatat walau listener
        | belum terpasang.
        */
        Schema::create('proc_events_log', function (Blueprint $table): void {
            $table->id();
            $table->string('event_name', 120);
            $table->string('entity_type', 80)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index('event_name');
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('proc_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->json('value')->nullable();
            $table->string('group', 40)->default('general');
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proc_settings');
        Schema::dropIfExists('proc_events_log');
        Schema::dropIfExists('proc_audit_logs');
        Schema::dropIfExists('proc_commitments');
        Schema::dropIfExists('proc_budgets');
        Schema::dropIfExists('proc_contract_items');
        Schema::dropIfExists('proc_contracts');
    }
};
