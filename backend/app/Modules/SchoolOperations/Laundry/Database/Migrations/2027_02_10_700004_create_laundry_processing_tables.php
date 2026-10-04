<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operasional pemrosesan: batch (§33), langkah proses (§35–§37, §42), log
 * transisi status (Rule 28), quality control (§38), dan insiden (§39/§40).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 50)->unique();
            // WASHING / DRYING / IRONING / FOLDING.
            $table->string('stage', 20);
            $table->foreignId('laundry_machine_id')->nullable()
                ->constrained('laundry_machines')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            // PLANNED / IN_PROGRESS / COMPLETED / CANCELLED.
            $table->string('status', 20)->default('PLANNED');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('total_weight', 10, 3)->default(0);
            $table->unsignedInteger('total_orders')->default(0);
            // Detail proses (§35/§36) — secukupnya, tanpa sensor.
            $table->string('program', 50)->nullable();
            $table->decimal('temperature_c', 5, 1)->nullable();
            $table->string('detergent')->nullable();
            $table->string('drying_method', 30)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'stage']);
        });

        Schema::create('laundry_batch_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_batch_id')->constrained('laundry_batches')->cascadeOnDelete();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->restrictOnDelete();
            $table->timestamp('added_at');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['laundry_batch_id', 'laundry_order_id']);
            $table->index('laundry_order_id');
        });

        Schema::create('laundry_processing_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->restrictOnDelete();
            // WASHING / DRYING / IRONING / FOLDING / PACKAGING.
            $table->string('step_type', 20);
            $table->foreignId('laundry_batch_id')->nullable()
                ->constrained('laundry_batches')->nullOnDelete();
            $table->foreignId('laundry_machine_id')->nullable()
                ->constrained('laundry_machines')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            // PENDING / IN_PROGRESS / COMPLETED.
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('program', 50)->nullable();
            $table->decimal('temperature_c', 5, 1)->nullable();
            $table->string('detergent')->nullable();
            $table->string('drying_method', 30)->nullable();
            $table->unsignedInteger('item_count')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['laundry_order_id', 'step_type']);
            $table->index(['step_type', 'status']);
        });

        // Jejak transisi status order (Rule 28: semua transisi diaudit).
        Schema::create('laundry_processing_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestamps();

            $table->index(['laundry_order_id', 'id']);
        });

        Schema::create('laundry_quality_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->restrictOnDelete();
            // checklist item => bool (§38).
            $table->json('checklist');
            // PASSED / FAILED / REWORK.
            $table->string('result', 10);
            $table->text('notes')->nullable();
            // Tujuan rework: WASHING / IRONING / dst.
            $table->string('rework_target_status', 30)->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index('laundry_order_id');
            $table->index('result');
        });

        Schema::create('laundry_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_number', 50)->unique();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->restrictOnDelete();
            $table->foreignId('laundry_order_item_id')->nullable()
                ->constrained('laundry_order_items')->nullOnDelete();
            // MISSING / DAMAGED / STAIN / COLOR_TRANSFER / OTHER (§39).
            $table->string('incident_type', 20);
            $table->text('description');
            $table->string('evidence_path')->nullable();
            $table->foreignId('responsible_operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            // REPLACEMENT / REFUND / CREDIT / FREE_QUOTA / WALLET_REFUND / MANUAL (§41).
            $table->string('compensation_type', 20)->nullable();
            $table->decimal('compensation_amount', 14, 2)->nullable();
            // Referensi kompensasi: nomor transaksi wallet / ledger entitlement.
            $table->string('compensation_reference')->nullable();
            // OPEN / INVESTIGATING / RESOLVED / CLOSED.
            $table->string('status', 20)->default('OPEN');
            // Kompensasi tidak otomatis tanpa approval (§41).
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('laundry_order_id');
            $table->index('status');
            $table->index('incident_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_incidents');
        Schema::dropIfExists('laundry_quality_checks');
        Schema::dropIfExists('laundry_processing_logs');
        Schema::dropIfExists('laundry_processing_steps');
        Schema::dropIfExists('laundry_batch_orders');
        Schema::dropIfExists('laundry_batches');
    }
};
