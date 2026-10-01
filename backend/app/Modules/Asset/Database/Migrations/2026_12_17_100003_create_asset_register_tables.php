<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Register aset — inti modul.
 *
 * `assets` adalah tempat semua pertanyaan operasional dijawab: di mana, milik siapa,
 * berapa nilainya, bagaimana kondisinya. Nilai keuangan disimpan sebagai SALINAN
 * operasional (harga perolehan, umur, residu) yang dipakai untuk usulan penggantian,
 * sedangkan angka buku yang mengikat tetap milik modul Keuangan (`finance_asset_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('asset_categories')->nullOnDelete();
            $table->foreignId('asset_type_id')->nullable()->constrained('asset_types')->nullOnDelete();
            $table->foreignId('parent_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->unsignedBigInteger('finance_asset_id')->nullable()->comment('Rujukan ke finance_assets; dijembatani kontrak FinanceProviderInterface');
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('custodian_type', 24)->nullable();
            $table->string('custodian_label', 160)->nullable()->comment('Nama penanggung jawab saat penugasan (tidak berubah bila pengguna berganti nama)');
            $table->unsignedBigInteger('active_loan_id')->nullable()->unique()->comment('Kunci basis data: satu aset hanya boleh punya satu pinjaman berjalan');
            $table->unsignedBigInteger('vendor_id')->nullable()->comment('Rujukan lintas modul; modul Vendor belum terpasang');
            $table->string('asset_code', 80)->unique();
            $table->string('asset_number', 40)->nullable();
            $table->string('asset_tag', 80)->nullable()->unique();
            $table->string('name', 200);
            $table->string('serial_number', 120)->nullable();
            $table->string('barcode', 120)->nullable();
            $table->string('qr_token', 64)->nullable()->unique();
            $table->string('rfid', 120)->nullable();
            $table->string('manufacturer', 120)->nullable();
            $table->string('brand', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('acquisition_source', 24)->default('PURCHASE');
            $table->date('purchase_date')->nullable();
            $table->date('received_date')->nullable();
            $table->date('commission_date')->nullable();
            $table->date('capitalization_date')->nullable();
            $table->decimal('acquisition_cost', 16, 2)->default(0);
            $table->decimal('residual_value', 16, 2)->default(0);
            $table->decimal('current_book_value', 16, 2)->nullable();
            $table->decimal('accumulated_depreciation', 16, 2)->default(0);
            $table->decimal('fair_value', 16, 2)->nullable();
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->string('depreciation_method', 32)->nullable();
            $table->date('depreciation_started_at')->nullable();
            $table->string('capitalization_decision', 24)->default('ASSET');
            $table->string('capitalization_threshold', 32)->nullable()->comment('Ambang yang BERLAKU saat aset dicatat; kebijakan baru tidak mengubah masa lalu');
            $table->string('status', 24)->default('REGISTERED');
            $table->string('condition', 24)->default('GOOD');
            $table->string('criticality', 24)->default('NORMAL');
            $table->string('tag_status', 24)->default('PENDING_TAGGING');
            $table->dateTime('tagged_at')->nullable();
            $table->date('warranty_start')->nullable();
            $table->date('warranty_end')->nullable();
            $table->string('warranty_provider', 160)->nullable();
            $table->string('warranty_number', 80)->nullable();
            $table->text('warranty_terms')->nullable();
            $table->string('warranty_claim_reference', 80)->nullable();
            $table->string('procurement_reference', 80)->nullable();
            $table->string('purchase_order_reference', 80)->nullable();
            $table->string('invoice_reference', 80)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('registered_at')->nullable();
            $table->date('expected_life_end')->nullable();
            $table->dateTime('last_inspection_at')->nullable();
            $table->dateTime('next_inspection_at')->nullable();
            $table->dateTime('last_maintenance_at')->nullable();
            $table->dateTime('next_maintenance_at')->nullable();
            $table->unsignedInteger('loan_count')->default(0);
            $table->unsignedInteger('maintenance_count')->default(0);
            $table->unsignedInteger('downtime_minutes')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1)->comment('Pengunci optimistis: perubahan bersamaan ditolak, bukan saling menimpa');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'condition']);
            $table->index(['location_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index('warranty_end');
            $table->index('next_maintenance_at');
        });
        Schema::create('asset_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('type', 24)->comment('CODE | BARCODE | QR | RFID | SERIAL');
            $table->string('value', 160);
            $table->boolean('is_primary')->default(false);
            $table->dateTime('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 200)->nullable();
            $table->timestamps();
            $table->unique(['type', 'value']);
        });
        Schema::create('asset_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('tag_code', 80);
            $table->string('status', 24)->default('PENDING_TAGGING');
            $table->dateTime('printed_at')->nullable();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('attached_at')->nullable();
            $table->foreignId('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('damaged_at')->nullable();
            $table->unsignedBigInteger('replaced_tag_id')->nullable()->comment('Label lama yang diganti — riwayat label tidak ditimpa');
            $table->string('notes', 200)->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'status']);
        });
        Schema::create('asset_specifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('key', 80);
            $table->text('value')->nullable();
            $table->string('unit', 24)->nullable();
            $table->string('source', 24)->default('MANUAL');
            $table->timestamps();
            $table->unique(['asset_id', 'key']);
        });
        Schema::create('asset_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('asset_attributes')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'attribute_id']);
        });
        Schema::create('asset_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('title', 200);
            $table->string('reference', 200)->nullable()->comment('Rujukan penyedia dokumen; berkas tidak disimpan dua kali di modul ini');
            $table->string('original_name', 200)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('is_private')->default(true);
            $table->date('expires_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['asset_id', 'document_type']);
        });
        Schema::create('facility_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('title', 200);
            $table->string('reference', 200)->nullable();
            $table->string('original_name', 200)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('is_private')->default(true);
            $table->date('expires_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('asset_depreciations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('period', 7)->comment('YYYY-MM');
            $table->string('method', 32);
            $table->decimal('amount', 16, 2);
            $table->decimal('accumulated_amount', 16, 2);
            $table->decimal('book_value', 16, 2);
            $table->string('status', 24)->default('POSTED');
            $table->string('finance_reference', 80)->nullable();
            $table->string('finance_status', 24)->default('PENDING');
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 200)->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'period']);
        });
        Schema::create('asset_valuations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('valuation_type', 32);
            $table->decimal('amount', 16, 2);
            $table->decimal('previous_book_value', 16, 2)->nullable();
            $table->date('effective_date');
            $table->string('reason', 200);
            $table->string('document_reference', 200)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['asset_id', 'valuation_type']);
        });
        Schema::create('asset_impairments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->decimal('amount', 16, 2);
            $table->string('reason', 200);
            $table->date('effective_date');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('finance_reference', 80)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_impairments');
        Schema::dropIfExists('asset_valuations');
        Schema::dropIfExists('asset_depreciations');
        Schema::dropIfExists('facility_documents');
        Schema::dropIfExists('asset_documents');
        Schema::dropIfExists('asset_attribute_values');
        Schema::dropIfExists('asset_specifications');
        Schema::dropIfExists('asset_tags');
        Schema::dropIfExists('asset_identifiers');
        Schema::dropIfExists('assets');
    }
};
