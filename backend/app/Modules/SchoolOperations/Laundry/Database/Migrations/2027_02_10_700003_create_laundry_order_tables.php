<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transaksi utama: order laundry (§9), item (§15), alokasi free/paid (§12),
 * laundry bag (§16), dan label digital (§31).
 *
 * Order tidak pernah dihapus (Rule 17) — soft delete hanya untuk koreksi
 * administratif. Harga disnapshot di order (Rule 9/23) dan setiap porsi
 * free/paid menjadi baris alokasi yang tertaut ke ledger entitlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30)->unique();
            // Rule 1: setiap order wajib punya santri.
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            // Snapshot asrama saat order dibuat (Boarding sumber kebenarannya).
            $table->foreignId('boarding_dormitory_id')->nullable()
                ->constrained('boarding_dormitories')->nullOnDelete();
            $table->foreignId('laundry_service_id')
                ->constrained('laundry_services')->restrictOnDelete();
            $table->foreignId('laundry_bag_id')->nullable();
            // Diserahkan oleh: santri sendiri / wali / petugas (§9).
            $table->string('submitted_by', 100)->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('DRAFT');
            $table->date('order_date');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('weighed_at')->nullable();
            $table->timestamp('due_at')->nullable();
            // Pengukuran mentah disimpan (§30): net = gross - bag.
            $table->decimal('gross_weight', 8, 3)->nullable();
            $table->decimal('bag_weight', 8, 3)->nullable();
            $table->decimal('net_weight', 8, 3)->nullable();
            $table->foreignId('weighed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('item_count')->default(0);
            // Snapshot jatah saat calculate (§12).
            $table->foreignId('laundry_entitlement_id')->nullable();
            $table->string('quota_type', 10)->nullable();
            $table->decimal('free_quantity', 12, 3)->default(0);
            $table->decimal('paid_quantity', 12, 3)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            // Pembebasan biaya lewat override beralasan (§57).
            $table->decimal('discount_amount', 14, 2)->default(0);
            // Snapshot aturan & langkah harga (Rule 9).
            $table->json('pricing_snapshot')->nullable();
            $table->string('payment_status', 20)->default('PENDING');
            $table->string('payment_method', 20)->nullable();
            // Rule 13: pembayaran berhasil wajib punya referensi wallet.
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->string('wallet_transaction_number', 50)->nullable();
            // §56: override pemrosesan tanpa pembayaran (diaudit).
            $table->boolean('allow_unpaid_processing')->default(false);
            $table->string('pickup_method', 20)->default('PICKUP');
            $table->string('delivery_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('override_reason')->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'status']);
            $table->index(['status', 'order_date']);
            $table->index('order_date');
            $table->index('payment_status');
            $table->index('laundry_service_id');
            $table->index('laundry_bag_id');
        });

        Schema::create('laundry_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->cascadeOnDelete();
            $table->string('item_type', 30)->default('OTHER');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('color', 30)->nullable();
            $table->string('description')->nullable();
            // GOOD / STAINED / DAMAGED / WET / DIRTY.
            $table->string('condition', 20)->nullable();
            // Snapshot harga piece (Rule 9).
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->string('tag_code', 50)->nullable();
            $table->string('rfid_tag', 64)->nullable();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('laundry_order_id');
            $table->index('tag_code');
        });

        Schema::create('laundry_order_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->cascadeOnDelete();
            // FREE / PAID (§12: split eksplisit per order).
            $table->string('allocation_type', 10);
            $table->string('quota_type', 10)->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            // 0 untuk FREE; nominal tagihan untuk PAID.
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->foreignId('laundry_pricing_rule_id')->nullable()
                ->constrained('laundry_pricing_rules')->nullOnDelete();
            // Tautan ke baris ledger USE/REVERSE entitlement (Rule 21).
            $table->unsignedBigInteger('entitlement_transaction_id')->nullable();
            $table->timestamps();

            $table->index(['laundry_order_id', 'allocation_type']);
        });

        Schema::create('laundry_bags', function (Blueprint $table) {
            $table->id();
            $table->string('bag_code', 50)->unique();
            $table->string('rfid_uid', 64)->nullable()->unique();
            $table->string('qr_code', 100)->nullable()->unique();
            // STANDARD / LARGE / DELICATE.
            $table->string('bag_type', 20)->default('STANDARD');
            $table->decimal('capacity_kg', 8, 2)->nullable();
            // Berat tas kosong utk pengurang timbangan (§30).
            $table->decimal('tare_weight', 8, 3)->default(0);
            // AVAILABLE / ASSIGNED / IN_USE / REPAIR / RETIRED.
            $table->string('status', 20)->default('AVAILABLE');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('laundry_bag_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_bag_id')->constrained('laundry_bags')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            // ACTIVE / RETURNED / LOST.
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['laundry_bag_id', 'status']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('laundry_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->cascadeOnDelete();
            $table->string('label_number', 50)->unique();
            // Isi QR (§31): order number, student, bag, weight, service, due.
            $table->text('qr_payload');
            $table->foreignId('laundry_bag_id')->nullable()->constrained('laundry_bags')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('laundry_order_id');
        });

        // FK laundry_orders.laundry_bag_id ditambahkan setelah laundry_bags ada.
        Schema::table('laundry_orders', function (Blueprint $table) {
            $table->foreign('laundry_bag_id')->references('id')
                ->on('laundry_bags')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('laundry_orders', function (Blueprint $table) {
            $table->dropForeign(['laundry_bag_id']);
        });
        Schema::dropIfExists('laundry_labels');
        Schema::dropIfExists('laundry_bag_assignments');
        Schema::dropIfExists('laundry_bags');
        Schema::dropIfExists('laundry_order_allocations');
        Schema::dropIfExists('laundry_order_items');
        Schema::dropIfExists('laundry_orders');
    }
};
