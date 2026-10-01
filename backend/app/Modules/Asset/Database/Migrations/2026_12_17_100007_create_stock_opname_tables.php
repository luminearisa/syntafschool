<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok opname: sesi penghitungan fisik, hasil per aset, dan selisihnya.
 *
 * Hasil pindai TIDAK mengubah status aset. Selisih hidup di barisnya sendiri dengan
 * keputusan yang harus diambil (verifikasi → investigasi → persetujuan), baru setelah
 * itu penyesuaian dicatat — sehingga "barang tidak ditemukan" tidak diam-diam menjadi
 * "barang hilang".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_stock_opnames', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('opname_number', 40)->unique();
            $table->string('title', 200);
            $table->string('status', 24)->default('PLANNED');
            $table->json('scope')->nullable()->comment('Kampus/gedung/lokasi/kategori yang dihitung');
            $table->string('method', 24)->default('MANUAL')->comment('MANUAL | BARCODE | QR | RFID | MOBILE');
            $table->date('scheduled_for')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedInteger('expected_count')->default(0);
            $table->unsignedInteger('found_count')->default(0);
            $table->unsignedInteger('variance_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'scheduled_for']);
        });
        Schema::create('asset_stock_opname_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opname_id')->constrained('asset_stock_opnames')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('expected_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedBigInteger('expected_custodian_id')->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scan_result', 24)->default('MISSING');
            $table->foreignId('actual_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedBigInteger('actual_custodian_id')->nullable();
            $table->string('condition_found', 24)->nullable();
            $table->boolean('is_variance')->default(false);
            $table->string('variance_decision', 24)->default('PENDING');
            $table->string('scanned_code', 120)->nullable()->comment('Kode yang dipindai; diisi walau asetnya tidak dikenali');
            $table->dateTime('adjustment_applied_at')->nullable();
            $table->text('adjustment_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['opname_id', 'scan_result']);
        });
        Schema::create('asset_stock_opname_variances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('opname_id')->constrained('asset_stock_opnames')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('asset_stock_opname_items')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('variance_type', 24);
            $table->text('description')->nullable();
            $table->string('decision', 24)->default('PENDING');
            $table->foreignId('investigated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->json('adjustment')->nullable()->comment('Perubahan yang benar-benar diterapkan setelah disetujui');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_stock_opname_variances');
        Schema::dropIfExists('asset_stock_opname_items');
        Schema::dropIfExists('asset_stock_opnames');
    }
};
