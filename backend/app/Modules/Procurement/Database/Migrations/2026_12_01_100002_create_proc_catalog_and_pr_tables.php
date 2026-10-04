<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Procurement & Vendor — Fase 2: kategori, katalog item, Purchase Request
 * (PR), persetujuan PR, dan peristiwa pengadaan gabungan (§8–§11, §37–§38).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Kategori pengadaan (hierarkis) ----------
        Schema::create('proc_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->foreignId('parent_id')->nullable()->constrained('proc_categories')->nullOnDelete();
            $table->string('item_type', 24)->default('GOODS');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'item_type']);
        });

        // ---------- Katalog item pengadaan (barang & jasa) ----------
        Schema::create('proc_items', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 60)->nullable()->unique();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('proc_categories')->nullOnDelete();
            $table->string('item_type', 24)->default('GOODS');
            $table->string('unit', 30)->default('unit');
            $table->string('specification', 255)->nullable();
            $table->foreignId('preferred_vendor_id')->nullable()->constrained('proc_vendors')->nullOnDelete();
            $table->decimal('estimated_price', 18, 2)->default(0);
            $table->string('tax_category', 40)->nullable();
            $table->string('inventory_item_ref', 60)->nullable(); // rujukan ke Inventory (bukan FK)
            $table->string('asset_category', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'item_type']);
            $table->index('name');
        });

        // ---------- Harga/lead time per vendor untuk suatu item ----------
        Schema::create('proc_item_vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('proc_items')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->string('vendor_sku', 60)->nullable();
            $table->decimal('last_price', 18, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();

            $table->unique(['item_id', 'vendor_id'], 'proc_item_vendor_unique');
        });

        // ---------- Purchase Request (PR) ----------
        Schema::create('proc_purchase_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->string('location', 150)->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->string('budget_reference', 60)->nullable();
            $table->string('project_reference', 60)->nullable();
            $table->string('method', 20)->default('RFQ');
            $table->date('requested_date')->nullable();
            $table->date('required_date');
            $table->string('priority', 16)->default('NORMAL');
            $table->string('purpose', 255)->nullable();
            $table->text('justification')->nullable();
            $table->text('notes')->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->string('status', 24)->default('DRAFT');
            $table->decimal('estimated_total', 18, 2)->default(0);
            $table->decimal('approved_total', 18, 2)->nullable();
            $table->string('workflow_instance_id', 80)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority']);
            $table->index('required_date');
            $table->index('requester_id');
        });

        Schema::create('proc_purchase_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('proc_purchase_requests')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('proc_items')->nullOnDelete();
            $table->string('description', 255);
            $table->string('specification', 255)->nullable();
            $table->string('item_type', 24)->default('GOODS');
            $table->decimal('quantity', 18, 4);
            $table->string('unit', 30)->default('unit');
            $table->decimal('estimated_unit_price', 18, 2)->default(0);
            $table->decimal('estimated_total', 18, 2)->default(0);
            $table->date('required_date')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('proc_categories')->nullOnDelete();
            $table->boolean('is_asset')->default(false);
            $table->boolean('is_inventory')->default(false);
            $table->string('project_reference', 60)->nullable();
            $table->string('budget_reference', 60)->nullable();
            $table->foreignId('preferred_vendor_id')->nullable()->constrained('proc_vendors')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('purchase_request_id');
        });

        Schema::create('proc_purchase_request_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('proc_purchase_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('step')->default(1);
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 60)->nullable();
            $table->string('status', 20)->default('PENDING'); // PENDING | APPROVED | REJECTED | SKIPPED
            $table->timestamp('decision_at')->nullable();
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            $table->index(['purchase_request_id', 'step']);
        });

        // ---------- Peristiwa pengadaan (konsolidasi beberapa PR) ----------
        Schema::create('proc_events', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('title', 180);
            $table->string('status', 24)->default('OPEN');
            $table->string('method', 20)->default('RFQ');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('estimated_total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('proc_event_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('proc_events')->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained('proc_purchase_requests')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'purchase_request_id'], 'proc_event_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proc_event_requests');
        Schema::dropIfExists('proc_events');
        Schema::dropIfExists('proc_purchase_request_approvals');
        Schema::dropIfExists('proc_purchase_request_items');
        Schema::dropIfExists('proc_purchase_requests');
        Schema::dropIfExists('proc_item_vendors');
        Schema::dropIfExists('proc_items');
        Schema::dropIfExists('proc_categories');
    }
};
