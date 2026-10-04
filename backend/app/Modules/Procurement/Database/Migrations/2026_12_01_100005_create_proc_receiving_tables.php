<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Procurement & Vendor — Fase 5: penerimaan barang (goods receipt),
 * inspeksi mutu, dan penerimaan jasa (service receipt) (§22–§27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proc_goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('purchase_order_id')->constrained('proc_purchase_orders')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->date('receipt_date');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->string('location', 150)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_note_number', 60)->nullable();
            $table->string('courier', 120)->nullable();
            $table->string('driver', 120)->nullable();
            $table->string('vehicle_number', 30)->nullable();
            $table->string('status', 24)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['purchase_order_id', 'status']);
            $table->index('receipt_date');
        });

        Schema::create('proc_goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('proc_goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('proc_purchase_order_items')->cascadeOnDelete();
            $table->decimal('ordered_quantity', 18, 4)->default(0);
            $table->decimal('delivered_quantity', 18, 4)->default(0);
            $table->decimal('accepted_quantity', 18, 4)->default(0);
            $table->decimal('rejected_quantity', 18, 4)->default(0);
            $table->decimal('damaged_quantity', 18, 4)->default(0);
            $table->decimal('backorder_quantity', 18, 4)->default(0);
            $table->string('unit', 30)->default('unit');
            $table->string('batch_number', 60)->nullable();
            $table->string('serial_number', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('inspection_status', 20)->default('PENDING');
            $table->json('recognized_asset_codes')->nullable(); // kode aset hasil registrasi modul Asset
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('goods_receipt_id');
        });

        Schema::create('proc_goods_receipt_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_item_id')->constrained('proc_goods_receipt_items')->cascadeOnDelete();
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('PENDING');
            $table->json('checklist')->nullable();
            $table->text('defect_notes')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamps();

            $table->index('goods_receipt_item_id');
        });

        Schema::create('proc_service_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('purchase_order_id')->constrained('proc_purchase_orders')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->date('service_period_start')->nullable();
            $table->date('service_period_end')->nullable();
            $table->string('milestone', 150)->nullable();
            $table->decimal('completion_percentage', 5, 2)->default(0);
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->string('status', 24)->default('DRAFT');
            $table->foreignId('responsible_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['purchase_order_id', 'status']);
        });

        Schema::create('proc_service_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_receipt_id')->constrained('proc_service_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('proc_purchase_order_items')->cascadeOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 18, 4)->default(0);
            $table->string('unit', 30)->default('unit');
            $table->decimal('hours', 10, 2)->nullable();
            $table->decimal('completion_percentage', 5, 2)->default(0);
            $table->decimal('accepted_quantity', 18, 4)->default(0);
            $table->string('status', 20)->default('PENDING');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('service_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proc_service_receipt_items');
        Schema::dropIfExists('proc_service_receipts');
        Schema::dropIfExists('proc_goods_receipt_inspections');
        Schema::dropIfExists('proc_goods_receipt_items');
        Schema::dropIfExists('proc_goods_receipts');
    }
};
