<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekening koran bank (Phase 4 §4) — bukti MUTASI BANK.
 *
 * Sebelum tabel ini ada, rekonsiliasi dibangun dari baris yang diketik ulang
 * tangan: hasilnya tidak bisa dibuktikan berasal dari dokumen bank, dan
 * import/re-import rekening koran tidak punya tempat. §4 mensyaratkan empat
 * entitas (Bank Statement, Bank Statement Line, Reconciliation, Reconciliation
 * Item); header + baris di bawah ini adalah dua yang pertama, sedangkan dua
 * lainnya sudah ada (`finance_reconciliations` + itemnya) dan ditautkan lewat
 * migrasi berikutnya.
 *
 * Kontrak yang dikunci di sini:
 * - mengimpor rekening koran TIDAK membuat jurnal (§4). Baris statement hanyalah
 *   data bank; jurnal hanya lahir dari workflow resmi (payment, adjustment,
 *   bank transaction). Karena itu tidak ada kolom finance_transaction_id.
 * - `journal_line_id` menunjuk baris jurnal buku KITA yang menjadi lawan
 *   pencocokan (bukan dokumen), sehingga transaksi bank yang lahir dari payment
 *   — yang tidak punya baris `finance_bank_transactions` — tetap bisa dicocokkan.
 * - `unique (bank_statement_id, external_id)` membuat import ulang file yang
 *   sama tidak menghasilkan duplikat baris (§23 idempotensi operasi sensitif).
 *
 * Arah nominal mengikuti rekening koran Indonesia: `debit` = uang keluar dari
 * rekening (termasuk biaya admin), `credit` = uang masuk, `amount` = perubahan
 * saldo bersih (credit − debit) sehingga `opening + Σ amount = closing`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_bank_statements', function (Blueprint $table): void {
            $table->id();
            $table->string('statement_number', 30)->unique();
            $table->foreignId('bank_account_id')->constrained('finance_bank_accounts')->cascadeOnDelete();
            // Tanggal akhir rekening koran; barisnya bisa membentang sebelum ini.
            $table->date('statement_date');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2)->default(0);
            $table->enum('source', ['manual', 'paste', 'csv'])->default('manual');
            // Identitas rekening koran dari bank (nomor/ID file). Kunci idempotensi
            // import: rekening + identitas yang sama = statement yang sama.
            $table->string('external_reference', 100)->nullable();
            $table->string('filename', 200)->nullable();
            $table->enum('status', ['imported', 'in_review', 'reconciled', 'cancelled'])->default('imported');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bank_account_id', 'statement_date']);
            $table->unique(['bank_account_id', 'external_reference']);
        });

        Schema::create('finance_bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('finance_bank_statements')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->nullable();
            $table->date('line_date')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            // Perubahan saldo menurut bank: credit - debit.
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('running_balance', 15, 2)->nullable();
            // ID transaksi dari bank, bila ada (dipakai sebagai kunci import ulang).
            $table->string('external_id', 100)->nullable();
            $table->enum('status', ['unmatched', 'matched', 'reconciled', 'excluded'])->default('unmatched');
            // Cara baris tercocok: external_id | reference | document_number |
            // amount_date | amount | manual. Ditampilkan di layar review supaya
            // pencocokan otomatis bisa dipertanyakan, bukan dipercaya buta.
            $table->string('match_method', 30)->nullable();
            $table->foreignId('journal_line_id')->nullable()
                ->constrained('finance_transaction_lines')->nullOnDelete();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['bank_statement_id', 'status']);
            $table->index(['bank_statement_id', 'line_date']);
            $table->index(['journal_line_id']);
            $table->unique(['bank_statement_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_bank_statement_lines');
        Schema::dropIfExists('finance_bank_statements');
    }
};
