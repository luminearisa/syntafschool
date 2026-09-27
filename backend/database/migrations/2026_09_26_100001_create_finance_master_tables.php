<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Keuangan — tabel MASTER (§4–§12, §34, §39–§40, §48, §50).
 *
 * Dipakai ulang, tidak diduplikasi (§71): `users`, `students`, `employees`,
 * `guardians`, `banks` (master institusi bank), `payment_methods`, `settings`
 * (konfigurasi grup `finance`), `document_sequences` (penomoran), `notifications`
 * dan `activity_log` (audit).
 *
 * Uang memakai decimal(15,2) konsisten dengan `fee_components` existing.
 * Aturan bisnis (saldo, status, approval) ditegakkan di service dengan
 * DB::transaction + lockForUpdate, bukan CHECK constraint — SQLite menolak
 * ALTER TABLE ADD CONSTRAINT dan skema dev/test harus sama dengan produksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Periode fiskal (§48, §49) — transaksi menolak masuk periode closed.
        Schema::create('finance_periods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->enum('type', ['yearly', 'monthly'])->default('monthly');
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['fiscal_year', 'status']);
        });

        // Chart of Accounts (§5, §6) — hierarchy lewat parent_id.
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->foreignId('parent_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->enum('normal_balance', ['debit', 'credit']);
            // Akun hasil seeding tidak boleh dihapus agar ledger tetap utuh.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
            $table->index('parent_id');
        });

        // Cost center (§7) — unit untuk mengukur pendapatan/pengeluaran/budget.
        Schema::create('finance_cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->foreignId('parent_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        // Budget per tahun/akun/cost center (§8) + alur approval (§9).
        Schema::create('finance_budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->string('period', 20)->nullable();
            $table->decimal('budget_amount', 15, 2)->default(0);
            $table->decimal('used_amount', 15, 2)->default(0);
            $table->enum('status', ['draft', 'submitted', 'approved', 'active', 'rejected', 'closed'])->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['fiscal_year', 'account_id', 'cost_center_id', 'period'], 'finance_budgets_unique_scope');
            $table->index('status');
        });

        // Jenis tagihan (§10) — dipetakan ke akun pendapatan (COA).
        Schema::create('finance_fee_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('category', 60)->nullable();
            $table->foreignId('account_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->decimal('default_amount', 15, 2)->default(0);
            $table->enum('frequency', ['once', 'monthly', 'semesterly', 'yearly', 'custom'])->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'category']);
        });

        // Paket tagihan (§11, §12) — bisa spesifik program/jenjang/kelas/asrama.
        Schema::create('finance_fee_packages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('level', 30)->nullable();
            $table->foreignId('class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->enum('boarding_type', ['boarding', 'non_boarding', 'all'])->default('all');
            $table->unsignedSmallInteger('academic_year')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'boarding_type']);
        });

        // Baris paket: jenis tagihan + nominal + frekuensi.
        Schema::create('finance_fee_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_package_id')->constrained('finance_fee_packages')->cascadeOnDelete();
            $table->foreignId('fee_type_id')->constrained('finance_fee_types')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->enum('frequency', ['once', 'monthly', 'semesterly', 'yearly', 'custom'])->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['fee_package_id', 'fee_type_id']);
        });

        // Kas (§39) — saldo bergerak lewat ledger engine.
        Schema::create('finance_cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Rekening bank (§40) — `bank_id` menunjuk master `banks` existing.
        Schema::create('finance_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('account_number', 60)->nullable();
            $table->string('account_name', 120)->nullable();
            $table->string('branch', 120)->nullable();
            $table->enum('account_type', ['operational', 'payroll', 'savings', 'other'])->default('operational');
            $table->foreignId('finance_account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Vendor (§34).
        Schema::create('finance_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->enum('vendor_type', ['company', 'individual', 'supplier', 'service'])->default('company');
            $table->string('tax_id', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->string('account_name', 120)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'vendor_type']);
        });

        // Beasiswa (§18) — pengurang tagihan per santri.
        Schema::create('finance_scholarships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->foreignId('fee_type_id')->nullable()->constrained('finance_fee_types')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['draft', 'active', 'expired', 'rejected', 'cancelled'])->default('draft');
            $table->timestamps();

            $table->index(['student_id', 'status']);
        });

        // Aturan diskon reusable (§17) — bisa dibatasi program/santri/fee/periode.
        Schema::create('finance_discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->enum('type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('value', 15, 2)->default(0);
            $table->foreignId('fee_type_id')->nullable()->constrained('finance_fee_types')->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_discounts');
        Schema::dropIfExists('finance_scholarships');
        Schema::dropIfExists('finance_vendors');
        Schema::dropIfExists('finance_bank_accounts');
        Schema::dropIfExists('finance_cash_accounts');
        Schema::dropIfExists('finance_fee_items');
        Schema::dropIfExists('finance_fee_packages');
        Schema::dropIfExists('finance_fee_types');
        Schema::dropIfExists('finance_budgets');
        Schema::dropIfExists('finance_cost_centers');
        Schema::dropIfExists('finance_accounts');
        Schema::dropIfExists('finance_periods');
    }
};
