<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Procurement & Vendor — Fase 4: Purchase Order (PO), item PO, versi
 * (amandemen non-destruktif), persetujuan PO, dan konfirmasi vendor (§17–§21).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proc_purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('proc_events')->nullOnDelete();
            $table->foreignId('rfq_id')->nullable()->constrained('proc_rfqs')->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('proc_quotations')->nullOnDelete();
            $table->foreignId('selection_id')->nullable()->constrained('proc_vendor_selections')->nullOnDelete();
            $table->date('po_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->string('payment_terms', 60)->nullable();
            $table->string('delivery_terms', 120)->nullable();
            $table->string('billing_address', 255)->nullable();
            $table->string('shipping_address', 255)->nullable();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->string('project_reference', 60)->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('shipping_amount', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->string('status', 24)->default('DRAFT');
            $table->unsignedInteger('current_version')->default(1);
            $table->string('workflow_instance_id', 80)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms_conditions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'vendor_id']);
            $table->index('expected_delivery_date');
            $table->index('po_date');
        });

        Schema::create('proc_purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('proc_purchase_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('proc_items')->nullOnDelete();
            $table->foreignId('purchase_request_item_id')->nullable()->constrained('proc_purchase_request_items')->nullOnDelete();
            $table->string('description', 255);
            $table->string('specification', 255)->nullable();
            $table->string('item_type', 24)->default('GOODS');
            $table->decimal('quantity', 18, 4);
            $table->string('unit', 30)->default('unit');
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('received_quantity', 18, 4)->default(0);
            $table->decimal('invoiced_quantity', 18, 4)->default(0);
            $table->date('required_delivery_date')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->boolean('is_asset')->default(false);
            $table->boolean('is_inventory')->default(false);
            $table->string('project_reference', 60)->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id');
        });

        // Snapshot tiap versi PO — histori tidak pernah ditimpa (§20).
        Schema::create('proc_purchase_order_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('proc_purchase_orders')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('reason', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('DRAFT');
            $table->boolean('is_material')->default(false);
            $table->json('changed_fields')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'version'], 'proc_po_version_unique');
        });

        Schema::create('proc_purchase_order_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('proc_purchase_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('step')->default(1);
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 60)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('decision_at')->nullable();
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'step']);
        });

        Schema::create('proc_vendor_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('proc_purchase_orders')->cascadeOnDelete();
            $table->string('status', 24)->default('PENDING');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->date('revised_delivery_date')->nullable();
            $table->text('revised_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proc_vendor_confirmations');
        Schema::dropIfExists('proc_purchase_order_approvals');
        Schema::dropIfExists('proc_purchase_order_versions');
        Schema::dropIfExists('proc_purchase_order_items');
        Schema::dropIfExists('proc_purchase_orders');
    }
};
