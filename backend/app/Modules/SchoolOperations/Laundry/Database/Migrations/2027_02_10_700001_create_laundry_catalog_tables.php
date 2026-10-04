<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog layanan laundry (§3), aturan harga (§4), dan mesin (§34).
 *
 * Harga disimpan sebagai rule berversi-efektif (effective_from/until) sehingga
 * perubahan harga tidak pernah mengubah order historis — order menyimpan
 * snapshot harganya sendiri (Rule 9 & 23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            // REGULAR/EXPRESS/BEDDING/BLANKET/SHOES/SPECIAL — configurable.
            $table->string('service_type', 30)->default('REGULAR');
            // WEIGHT / PIECE / SERVICE (§4).
            $table->string('pricing_model', 20)->default('WEIGHT');
            // KG / PIECE / ORDER.
            $table->string('unit', 20)->default('KG');
            // Estimasi waktu proses (jam) — dipakai menghitung due date.
            $table->unsignedInteger('processing_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'service_type']);
        });

        Schema::create('laundry_pricing_rules', function (Blueprint $table) {
            $table->id();
            // Null = berlaku untuk semua layanan (fallback).
            $table->foreignId('laundry_service_id')->nullable()
                ->constrained('laundry_services')->cascadeOnDelete();
            $table->string('pricing_model', 20)->default('WEIGHT');
            // Untuk model PIECE: jenis item (SHIRT/PANTS/...); null = default.
            $table->string('item_type', 30)->nullable();
            $table->decimal('unit_price', 12, 2)->default(0);
            // Minimum kuantitas ditagih, mis. 3 kg (§4 "Minimum 3 kg").
            $table->decimal('minimum_quantity', 8, 3)->nullable();
            // Lantai nominal tagihan (Rp).
            $table->decimal('minimum_charge', 12, 2)->nullable();
            // Untuk rule biaya tambahan: EXPRESS/STAIN_TREATMENT/... (§4).
            $table->string('fee_type', 30)->nullable();
            $table->decimal('fee_amount', 12, 2)->nullable();
            $table->decimal('fee_percent', 5, 2)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            // Rule lebih spesifik (service+item) menang; priority pemecah seri.
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['laundry_service_id', 'pricing_model', 'is_active']);
            $table->index(['fee_type', 'is_active']);
            $table->index(['effective_from', 'effective_until']);
        });

        Schema::create('laundry_machines', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            // WASHING / DRYING / IRONING.
            $table->string('machine_type', 20)->default('WASHING');
            $table->decimal('capacity_kg', 8, 2)->nullable();
            $table->string('location', 100)->nullable();
            // OPERATIONAL / MAINTENANCE / OUT_OF_SERVICE.
            $table->string('status', 20)->default('OPERATIONAL');
            // Tautan opsional ke register Asset (§34).
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('maintenance_state', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['machine_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_machines');
        Schema::dropIfExists('laundry_pricing_rules');
        Schema::dropIfExists('laundry_services');
    }
};
