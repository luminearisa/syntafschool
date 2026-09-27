<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menautkan rekonsiliasi (§4) ke rekening koran yang direkonsiliasi.
 *
 * Sebelumnya `finance_reconciliations` berdiri sendiri: angkanya diwakili baris
 * yang diketik tangan, sehingga tidak ada yang bisa diperiksa "rekonsiliasi ini
 * menutup rekening koran yang mana". Dengan `bank_statement_id` di header dan
 * `bank_statement_line_id` di item, rantai §28 menjadi utuh:
 * rekening koran → barisnya → item rekonsiliasi → baris jurnal → dokumen sumber.
 *
 * Baris lama tetap valid (kolom nullable); hanya rekonsiliasi berbasis statement
 * yang terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_reconciliations', function (Blueprint $table): void {
            $table->foreignId('bank_statement_id')->nullable()
                ->after('bank_account_id')
                ->constrained('finance_bank_statements')->nullOnDelete();
        });

        Schema::table('finance_reconciliation_items', function (Blueprint $table): void {
            $table->foreignId('bank_statement_line_id')->nullable()
                ->after('reconciliation_id')
                ->constrained('finance_bank_statement_lines')->cascadeOnDelete();
            $table->foreignId('journal_line_id')->nullable()
                ->after('bank_transaction_id')
                ->constrained('finance_transaction_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_reconciliation_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('journal_line_id');
            $table->dropConstrainedForeignId('bank_statement_line_id');
        });

        Schema::table('finance_reconciliations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_statement_id');
        });
    }
};
