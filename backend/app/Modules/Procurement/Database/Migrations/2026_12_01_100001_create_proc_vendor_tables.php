<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Procurement & Vendor — Fase 1: master Vendor.
 *
 * Seluruh tabel berprefix `proc_`. Kolom jenis/status memakai `string` yang
 * nilainya diikat enum PHP di `App\Modules\Procurement\Domain\Enums`, sehingga
 * menambah jenis tidak memerlukan ALTER TABLE.
 *
 * Rujukan lintas modul (departemen, unit, kampus, cost center) sengaja TIDAK
 * diberi foreign key agar modul ini tidak bergantung pada urutan migrasi modul
 * lain; keutuhannya ditegakkan di lapisan aplikasi. Rujukan ke `users` dan
 * antar-tabel `proc_*` tetap memakai foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Vendor / rekanan ----------
        Schema::create('proc_vendors', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('legal_name', 180);
            $table->string('trading_name', 180)->nullable();
            $table->string('vendor_type', 32)->default('SUPPLIER');
            $table->string('entity_type', 16)->default('COMPANY'); // COMPANY | INDIVIDUAL
            $table->string('npwp', 40)->nullable();
            $table->string('nib', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('province', 80)->nullable();
            $table->string('country', 80)->default('Indonesia');
            $table->string('postal_code', 12)->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website', 150)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->string('payment_terms', 60)->nullable();
            $table->string('tax_name', 60)->nullable();
            $table->string('tax_number', 40)->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('status', 24)->default('DRAFT');
            $table->string('classification', 24)->default('NORMAL');
            $table->string('tier', 16)->default('TIER_3');
            $table->string('risk_level', 16)->default('LOW');
            $table->text('notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'vendor_type']);
            $table->index(['classification', 'risk_level']);
            $table->index('legal_name');
        });

        // ---------- Kontak vendor ----------
        Schema::create('proc_vendor_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('position', 80)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'is_primary']);
        });

        // ---------- Alamat vendor (kantor, gudang, penagihan, pengiriman) ----------
        Schema::create('proc_vendor_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->string('type', 20)->default('OFFICE'); // OFFICE | WAREHOUSE | BILLING | SHIPPING
            $table->string('label', 80)->nullable();
            $table->string('address', 255);
            $table->string('city', 80)->nullable();
            $table->string('province', 80)->nullable();
            $table->string('country', 80)->default('Indonesia');
            $table->string('postal_code', 12)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['vendor_id', 'type']);
        });

        // ---------- Rekening bank vendor ----------
        Schema::create('proc_vendor_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->string('bank_name', 120);
            $table->string('account_number', 40);
            $table->string('account_name', 150);
            $table->string('branch', 120)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->unique(['vendor_id', 'account_number', 'bank_name'], 'proc_vendor_bank_unique');
        });

        // ---------- Dokumen vendor (onboarding & kepatuhan) ----------
        Schema::create('proc_vendor_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->string('type', 32)->default('LEGAL');
            $table->string('name', 150);
            $table->string('number', 80)->nullable();
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->boolean('is_mandatory')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index('expiry_date');
        });

        // ---------- Kategori vendor (many-to-many) ----------
        Schema::create('proc_vendor_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->unsignedBigInteger('category_id');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['vendor_id', 'category_id'], 'proc_vendor_category_unique');
            $table->index('category_id');
        });

        // ---------- Catatan kinerja vendor (scorecard per periode) ----------
        Schema::create('proc_vendor_performance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('proc_vendors')->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->unsignedInteger('po_count')->default(0);
            $table->unsignedInteger('on_time_count')->default(0);
            $table->unsignedInteger('late_count')->default(0);
            $table->decimal('total_ordered_qty', 18, 4)->default(0);
            $table->decimal('total_received_qty', 18, 4)->default(0);
            $table->decimal('rejected_qty', 18, 4)->default(0);
            $table->unsignedInteger('return_count')->default(0);
            $table->unsignedInteger('complaint_count')->default(0);
            $table->unsignedInteger('invoice_count')->default(0);
            $table->unsignedInteger('accurate_invoice_count')->default(0);
            $table->decimal('avg_delay_days', 8, 2)->default(0);
            $table->decimal('response_time_hours', 8, 2)->default(0);
            $table->decimal('on_time_rate', 5, 2)->default(0);
            $table->decimal('quality_rate', 5, 2)->default(0);
            $table->decimal('fulfillment_rate', 5, 2)->default(0);
            $table->decimal('invoice_accuracy_rate', 5, 2)->default(0);
            $table->decimal('score', 5, 2)->default(0);
            $table->string('grade', 4)->nullable(); // A | B | C | D
            $table->text('notes')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'period'], 'proc_vendor_performance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proc_vendor_performance_records');
        Schema::dropIfExists('proc_vendor_categories');
        Schema::dropIfExists('proc_vendor_documents');
        Schema::dropIfExists('proc_vendor_bank_accounts');
        Schema::dropIfExists('proc_vendor_addresses');
        Schema::dropIfExists('proc_vendor_contacts');
        Schema::dropIfExists('proc_vendors');
    }
};
