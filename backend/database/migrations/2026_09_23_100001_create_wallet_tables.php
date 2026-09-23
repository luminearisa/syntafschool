<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet / E-Wallet — core saldo.
 *
 * Master pemilik (students, employees, guardians, users), master bank (`banks`),
 * penomoran (`document_sequences`), konfigurasi (`settings`) dan audit
 * (`activity_log`) semuanya **dipakai ulang** — tidak ada tabel master baru.
 *
 * Saldo tidak pernah ditulis langsung: `balance` pada `wallets` adalah akibat dari
 * ledger `wallet_transactions` yang di-post lewat `WalletLedgerService` dengan
 * baris terkunci. Aturan "saldo tidak boleh negatif" dan "tepat satu pemilik"
 * ditegakkan di service itu (bukan CHECK database) karena SQLite menolak
 * `ALTER TABLE ... ADD CONSTRAINT`, sehingga constraint DB akan membuat skema
 * dev/test dan produksi berperilaku berbeda.
 *
 * Transaksi pembelian (kantin/POS/koperasi) sengaja belum ada di fase ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Rekening sekolah: tujuan setoran manual dan rekening pencairan.
        Schema::create('wallet_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('banks')->cascadeOnDelete();
            $table->string('account_number', 50);
            $table->string('account_name', 100);
            // topup = rekening tujuan setoran; withdrawal = rekening pencairan sekolah.
            $table->enum('purpose', ['topup', 'withdrawal', 'both'])->default('both');
            $table->boolean('is_active')->default(true);
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['bank_id', 'account_number']);
            $table->index('purpose');
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();

            // Pemilik wallet: tepat satu dari empat referensi person.
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('balance', 14, 2)->default(0);
            // Dana yang ditahan permintaan penarikan yang belum selesai.
            $table->decimal('held_balance', 14, 2)->default(0);
            $table->enum('status', ['active', 'blocked', 'closed'])->default('active');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('block_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'number']);
            $table->index(['employee_id', 'number']);
            $table->index(['guardian_id', 'number']);
            $table->index(['user_id', 'number']);
            $table->index('status');
        });

        // Ledger: fakta pembukuan. Baris tidak pernah diedit atau dihapus —
        // koreksi dibuat lewat baris REVERSAL baru.
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->string('transaction_number', 50)->unique();
            $table->enum('type', [
                'topup', 'withdraw', 'adjustment_credit', 'adjustment_debit', 'refund', 'reversal',
            ]);
            $table->enum('direction', ['credit', 'debit']);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_before', 14, 2);
            $table->decimal('balance_after', 14, 2);
            // `reversed` menandai transaksi asli yang sudah dikoreksi; nominalnya
            // tidak pernah diubah.
            $table->enum('status', ['posted', 'reversed'])->default('posted');
            // Rujukan sumber: WalletTopUp | WalletWithdrawal | WalletAdjustment.
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            // Baris lawan untuk reversal.
            $table->foreignId('source_transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'id']);
            $table->index(['wallet_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('status');
        });

        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->enum('method', ['gateway', 'manual'])->default('manual');

            // Status permintaan top up — sengaja dipisah dari status pembayaran
            // dan dari ledger (§14): satu sama lain tidak boleh saling menggantikan.
            $table->enum('status', [
                'pending_payment', 'awaiting_verification', 'verified',
                'rejected', 'expired', 'cancelled', 'completed',
            ])->default('pending_payment');

            // Status pembayaran (gateway maupun bukti transfer manual).
            $table->enum('payment_status', ['unpaid', 'pending', 'paid', 'failed', 'expired', 'refunded'])
                ->default('unpaid');
            $table->string('provider', 30)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->json('payment_payload')->nullable();
            $table->timestamp('payment_expired_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Transfer manual: rekening asal + bukti.
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('source_account_number', 50)->nullable();
            $table->string('source_account_name', 100)->nullable();
            $table->date('transferred_on')->nullable();
            $table->string('proof_path')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            // Saldo baru bertambah lewat ledger ini.
            $table->foreignId('transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
            $table->index('status');
            $table->unique(['provider', 'provider_reference']);
        });

        Schema::create('wallet_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->enum('status', [
                'pending', 'approved', 'processing', 'completed',
                'rejected', 'failed', 'cancelled',
            ])->default('pending');

            // Rekening tujuan pencairan.
            $table->enum('method', ['bank_transfer', 'cash'])->default('bank_transfer');
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('destination_account_number', 50)->nullable();
            $table->string('destination_account_name', 100)->nullable();
            $table->foreignId('wallet_bank_account_id')->nullable()
                ->constrained('wallet_bank_accounts')->nullOnDelete();

            // Berapa besar saldo wallet yang ditahan permintaan ini, dan apakah
            // tahanannya sudah dilepas / sudah didebit — dua hal berbeda (§17–§18).
            $table->decimal('held_amount', 14, 2)->default(0);
            $table->boolean('hold_released')->default(false);
            $table->boolean('debited')->default(false);

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('transfer_proof_path')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
            $table->index('status');
        });

        Schema::create('wallet_rfid_cards', function (Blueprint $table) {
            $table->id();
            // UID fisik kartu — unik global supaya satu kartu tidak menunjuk dua wallet.
            $table->string('uid', 64)->unique();
            $table->string('serial_number', 64)->nullable();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->enum('status', ['active', 'inactive', 'blocked', 'lost', 'replaced'])->default('active');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            // Kartu pengganti bila kartu ini hilang / rusak.
            $table->foreignId('replaced_by_card_id')->nullable()
                ->constrained('wallet_rfid_cards')->nullOnDelete();
            $table->string('issuing_batch', 50)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
            $table->index('status');
        });

        Schema::create('wallet_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->enum('direction', ['credit', 'debit']);
            $table->decimal('amount', 14, 2);
            // Alasan wajib: koreksi saldo tanpa dokumen sumber tidak boleh ada.
            $table->string('reason', 500);
            $table->string('reference_document', 100)->nullable();
            $table->string('proof_path')->nullable();
            $table->enum('status', ['pending', 'approved', 'posted', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
            $table->index('status');
        });

        // Koreksi atas transaksi yang salah: baris asli tetap ada, reversal
        // membuat ledger lawan. Tanpa kolom status — alurnya satu langkah (§37 FLOW 8).
        Schema::create('wallet_reversals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('transaction_id')->constrained('wallet_transactions')->restrictOnDelete();
            $table->foreignId('reversal_transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['wallet_id', 'id']);
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_reversals');
        Schema::dropIfExists('wallet_adjustments');
        Schema::dropIfExists('wallet_rfid_cards');
        Schema::dropIfExists('wallet_withdrawals');
        Schema::dropIfExists('wallet_topups');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('wallet_bank_accounts');
    }
};
