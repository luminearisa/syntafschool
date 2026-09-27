<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 4 / Transaksi kas (§1–§2 Phase 4).
 *
 * Kas selama ini hanya bergerak sebagai efek samping dokumen lain (pembayaran,
 * refund, pengeluaran, transfer, adjustment). Itu benar untuk uang yang memang
 * punya dokumen, tetapi operasional harian juga butuh mencatat mutasi kas
 * langsung — setoran ke kas besar, penarikan kas kecil, pengisian ulang laci
 * kantin. Tabel ini adalah dokumen sumbernya; jurnalnya tetap dibuat
 * {@see FinancialTransactionEngine}, sehingga tidak ada satu pun pergerakan kas
 * yang terjadi tanpa double-entry (§2: "tidak boleh ada transaksi kas yang
 * hanya mengubah angka saldo tanpa journal").
 *
 * Bentuknya sengaja cermin `finance_bank_transactions` (deposit/withdrawal):
 * dua tabel kembar untuk dua jenis rekening, bukan satu tabel abstrak dengan
 * `account_type` — sama seperti kas dan bank memang dua entitas berbeda di COA
 * (1100 vs 1200) dan di subledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 30)->unique();
            $table->foreignId('cash_account_id')->constrained('finance_cash_accounts')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->enum('type', ['cash_in', 'cash_out']);
            $table->decimal('amount', 15, 2)->default(0);
            // Akun lawan COA (Piutang/Pendapatan/Beban/…): tanpa kolom ini
            // pembuat transaksi harus menebak lawannya dan jurnal tidak seimbang.
            $table->foreignId('contra_account_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->string('reference', 80)->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('cashier_session_id')->nullable();
            $table->foreignId('finance_transaction_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cash_account_id', 'transaction_date']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cash_transactions');
    }
};
