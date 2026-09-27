<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Keuangan — tabel TRANSAKSIONAL (§13–§51).
 *
 * Alur uang dipisah tegas (§1): Billing → Invoice → Payment → Receipt →
 * Financial Transaction (double entry) → Ledger. Pengeluaran: Expense Request
 * → Approval → Payment → Transaction → Ledger.
 *
 * `finance_transactions` + `finance_transaction_lines` adalah satu-satunya buku
 * besar; semua transaksi confirmed bermuara ke sana lewat ledger engine (§46,
 * §47, §72) dengan validasi SUM(debit) == SUM(credit).
 *
 * Kolom `reference` unik pada billing mencegah duplicate generation (§14).
 * Idempotensi webhook gateway dijaga unique (`gateway`, `gateway_transaction_id`)
 * (§66). Saldo invoice/billing tidak berubah sebelum approval (§21).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ===== Penagihan (§13–§18) =====================================
        Schema::create('finance_billings', function (Blueprint $table) {
            $table->id();
            $table->string('billing_number', 30)->unique();
            $table->enum('customer_type', ['student', 'employee', 'guardian', 'vendor', 'other'])->default('student');
            $table->unsignedBigInteger('customer_id');
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->enum('source', ['manual', 'package', 'recurring', 'ppdb', 'boarding', 'library', 'other'])->default('manual');
            // Kunci idempotensi generation: student|fee|period (§14).
            $table->string('reference', 190)->nullable()->unique();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('scholarship_amount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->enum('status', ['draft', 'issued', 'partial', 'paid', 'overdue', 'cancelled'])->default('draft');
            $table->foreignId('invoice_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_type', 'customer_id']);
            $table->index(['status', 'due_date']);
            $table->index('student_id');
        });

        Schema::create('finance_billing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_id')->constrained('finance_billings')->cascadeOnDelete();
            $table->foreignId('fee_type_id')->nullable()->constrained('finance_fee_types')->nullOnDelete();
            $table->string('description', 200);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('scholarship_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();

            $table->index('billing_id');
        });

        // ===== Invoice (§16) ===========================================
        Schema::create('finance_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 30)->unique();
            $table->foreignId('billing_id')->nullable()->constrained('finance_billings')->nullOnDelete();
            $table->enum('customer_type', ['student', 'employee', 'guardian', 'vendor', 'other'])->default('student');
            $table->unsignedBigInteger('customer_id');
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);
            $table->enum('status', ['draft', 'issued', 'partial', 'paid', 'overdue', 'cancelled'])->default('draft');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_type', 'customer_id']);
            $table->index(['status', 'due_date']);
            $table->index('billing_id');
        });

        Schema::create('finance_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('finance_invoices')->cascadeOnDelete();
            $table->foreignId('fee_type_id')->nullable()->constrained('finance_fee_types')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->string('description', 200);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();

            $table->index('invoice_id');
        });

        // ===== Kasir (§22, §23) — payment tunai butuh sesi terbuka ======
        Schema::create('finance_cashier_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_number', 30)->unique();
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cash_account_id')->constrained('finance_cash_accounts')->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_cash', 15, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('actual_cash', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();
            $table->string('difference_reason', 500)->nullable();
            $table->enum('status', ['open', 'closed', 'reviewed'])->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['cashier_id', 'status']);
        });

        // ===== Pembayaran (§19–§22) ====================================
        Schema::create('finance_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 30)->unique();
            $table->foreignId('invoice_id')->nullable()->constrained('finance_invoices')->nullOnDelete();
            $table->foreignId('billing_id')->nullable()->constrained('finance_billings')->nullOnDelete();
            $table->enum('customer_type', ['student', 'employee', 'guardian', 'vendor', 'other'])->default('student');
            $table->unsignedBigInteger('customer_id');
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->enum('method', ['cash', 'bank_transfer', 'virtual_account', 'payment_gateway', 'wallet', 'manual_transfer'])->default('cash');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->enum('status', ['pending', 'paid', 'failed', 'expired', 'cancelled', 'refunded', 'partial'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            // Gateway (§20, §66) — idempotensi webhook.
            $table->string('gateway', 40)->nullable();
            $table->string('gateway_transaction_id', 120)->nullable();
            $table->string('reference_number', 80)->nullable();
            $table->string('proof_path', 255)->nullable();
            $table->foreignId('cash_account_id')->nullable()->constrained('finance_cash_accounts')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('finance_bank_accounts')->nullOnDelete();
            $table->foreignId('cashier_session_id')->nullable()->constrained('finance_cashier_sessions')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_transaction_id'], 'finance_payments_gateway_unique');
            $table->index(['customer_type', 'customer_id']);
            $table->index('status');
            $table->index('invoice_id');
        });

        // ===== Kuitansi (§24, §25) — batal = reversal, bukan hapus ======
        Schema::create('finance_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 30)->unique();
            $table->foreignId('payment_id')->constrained('finance_payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('finance_invoices')->nullOnDelete();
            $table->enum('customer_type', ['student', 'employee', 'guardian', 'vendor', 'other'])->default('student');
            $table->unsignedBigInteger('customer_id');
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('method', 40)->nullable();
            $table->timestamp('received_at');
            $table->boolean('is_cancelled')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('reversal_receipt_id')->nullable()->constrained('finance_receipts')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('payment_id');
        });

        // ===== Refund (§29) ============================================
        Schema::create('finance_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number', 30)->unique();
            $table->foreignId('payment_id')->constrained('finance_payments')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reason', 500);
            $table->enum('status', ['requested', 'approved', 'processing', 'completed', 'rejected', 'failed'])->default('requested');
            $table->foreignId('cash_account_id')->nullable()->constrained('finance_cash_accounts')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('finance_bank_accounts')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->foreignId('finance_transaction_id')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'status']);
        });

        // ===== Pengeluaran (§30–§33) ===================================
        Schema::create('finance_expense_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 30)->unique();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->constrained('finance_cost_centers')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('finance_vendors')->nullOnDelete();
            $table->string('payee_name', 150)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('description', 500);
            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->enum('status', ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'processing', 'paid', 'cancelled'])->default('draft');
            $table->unsignedTinyInteger('current_approval_level')->default(0);
            $table->unsignedTinyInteger('required_approval_levels')->default(1);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_account_id')->nullable()->constrained('finance_cash_accounts')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('finance_bank_accounts')->nullOnDelete();
            $table->foreignId('finance_transaction_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'cost_center_id']);
            $table->index('requester_id');
        });

        Schema::create('finance_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number', 30)->unique();
            $table->foreignId('expense_request_id')->nullable()->constrained('finance_expense_requests')->nullOnDelete();
            $table->foreignId('cost_center_id')->constrained('finance_cost_centers')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('finance_vendors')->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('description', 500);
            $table->date('expense_date');
            $table->foreignId('payment_id')->nullable()->constrained('finance_payments')->nullOnDelete();
            $table->foreignId('finance_transaction_id')->nullable();
            $table->enum('status', ['recorded', 'paid', 'cancelled'])->default('recorded');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cost_center_id', 'account_id']);
        });

        // ===== Vendor invoice + AP (§35, §36) ==========================
        Schema::create('finance_vendor_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('vendor_invoice_number', 40)->unique();
            $table->foreignId('vendor_id')->constrained('finance_vendors')->cascadeOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);
            $table->enum('status', ['draft', 'received', 'partially_paid', 'paid', 'overdue', 'cancelled'])->default('received');
            $table->foreignId('purchase_order_id')->nullable();
            $table->foreignId('expense_request_id')->nullable()->constrained('finance_expense_requests')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });

        // ===== Purchasing (§37, §38) ===================================
        Schema::create('finance_purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 30)->unique();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->string('description', 500)->nullable();
            $table->date('needed_date')->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'cancelled'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('finance_purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('finance_purchase_requests')->cascadeOnDelete();
            $table->string('item_name', 200);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('estimated_price', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->foreignId('account_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('finance_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 30)->unique();
            $table->foreignId('vendor_id')->constrained('finance_vendors')->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->nullable()->constrained('finance_purchase_requests')->nullOnDelete();
            $table->date('po_date');
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->enum('status', ['draft', 'submitted', 'approved', 'ordered', 'partially_received', 'received', 'cancelled'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });

        Schema::create('finance_purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('finance_purchase_orders')->cascadeOnDelete();
            $table->string('item_name', 200);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('received_quantity')->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();
        });

        // ===== Bank & kas ops (§41–§45) ================================
        Schema::create('finance_bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('finance_bank_accounts')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->enum('type', ['deposit', 'withdrawal', 'transfer', 'fee', 'adjustment']);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reference', 80)->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('transfer_id')->nullable();
            $table->foreignId('finance_transaction_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bank_account_id', 'transaction_date']);
        });

        Schema::create('finance_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number', 30)->unique();
            $table->enum('type', ['bank', 'cash'])->default('bank');
            $table->enum('source_type', ['bank_account', 'cash_account']);
            $table->unsignedBigInteger('source_id');
            $table->enum('destination_type', ['bank_account', 'cash_account']);
            $table->unsignedBigInteger('destination_id');
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('transfer_date');
            $table->string('reason', 500)->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'completed'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->foreignId('finance_transaction_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('finance_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('finance_bank_accounts')->cascadeOnDelete();
            $table->date('statement_date');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('bank_balance', 15, 2)->default(0);
            $table->decimal('system_balance', 15, 2)->default(0);
            $table->decimal('difference', 15, 2)->default(0);
            $table->enum('status', ['in_progress', 'reconciled', 'discrepancy'])->default('in_progress');
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'statement_date']);
        });

        Schema::create('finance_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('finance_reconciliations')->cascadeOnDelete();
            $table->date('transaction_date')->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('bank_amount', 15, 2)->default(0);
            $table->foreignId('bank_transaction_id')->nullable()->constrained('finance_bank_transactions')->nullOnDelete();
            $table->enum('match_status', ['matched', 'unmatched', 'ignored', 'adjusted', 'reconciled'])->default('unmatched');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['reconciliation_id', 'match_status']);
        });

        // ===== Adjustment (§51) ========================================
        Schema::create('finance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number', 30)->unique();
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->enum('direction', ['increase', 'decrease'])->default('increase');
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('adjustment_date');
            $table->string('reason', 500);
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'posted'])->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->foreignId('finance_transaction_id')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // ===== Buku besar double-entry (§46, §47, §72) =================
        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 30)->unique();
            $table->date('transaction_date');
            $table->string('description', 500);
            // Tipe 'invoice'/'payable'/'write_off' ditambahkan Phase 3 (§3); daftar
            // ini harus sama dengan FinancialTransaction::TYPES dan di-ALTER ulang
            // lewat migrasi 100006 untuk database yang sudah terbentuk.
            $table->enum('type', ['revenue', 'expense', 'payment', 'refund', 'transfer', 'adjustment', 'opening_balance', 'invoice', 'payable', 'write_off'])->default('payment');
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('finance_periods')->nullOnDelete();
            $table->decimal('total_debit', 15, 2)->default(0);
            $table->decimal('total_credit', 15, 2)->default(0);
            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->foreignId('reversed_by_transaction_id')->nullable()->constrained('finance_transactions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['transaction_date', 'type']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('period_id');
        });

        Schema::create('finance_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_transaction_id')->constrained('finance_transactions')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('description', 300)->nullable();
            $table->timestamps();

            $table->index(['finance_transaction_id', 'account_id']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transaction_lines');
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_adjustments');
        Schema::dropIfExists('finance_reconciliation_items');
        Schema::dropIfExists('finance_reconciliations');
        Schema::dropIfExists('finance_transfers');
        Schema::dropIfExists('finance_bank_transactions');
        Schema::dropIfExists('finance_purchase_order_items');
        Schema::dropIfExists('finance_purchase_orders');
        Schema::dropIfExists('finance_purchase_request_items');
        Schema::dropIfExists('finance_purchase_requests');
        Schema::dropIfExists('finance_vendor_invoices');
        Schema::dropIfExists('finance_expenses');
        Schema::dropIfExists('finance_expense_requests');
        Schema::dropIfExists('finance_refunds');
        Schema::dropIfExists('finance_receipts');
        Schema::dropIfExists('finance_payments');
        Schema::dropIfExists('finance_cashier_sessions');
        Schema::dropIfExists('finance_invoice_items');
        Schema::dropIfExists('finance_invoices');
        Schema::dropIfExists('finance_billing_items');
        Schema::dropIfExists('finance_billings');
    }
};
