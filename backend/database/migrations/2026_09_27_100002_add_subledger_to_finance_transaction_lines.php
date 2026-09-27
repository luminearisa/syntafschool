<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 4 / Stempel subledger pada baris jurnal (§1).
 *
 * Baris jurnal dicatat pada control account COA — `1100 Kas` — sedangkan saldo
 * yang dipakai operasional hidup di rekening konkret (`KAS-SEKOLAH`,
 * `KAS-PESANTREN`). Tanpa tautan antara keduanya, `current_balance` hanya bisa
 * dipercaya sebagai angka yang ditulis engine dan tidak bisa dihitung ulang dari
 * buku besar: pertanyaan "berapa kas tiap unit?" tidak punya jawaban kedua.
 *
 * Kolom ini membuat setiap baris kas/bank menunjuk rekening konkret yang
 * digerakkannya, sehingga cached balance = opening + Σ baris yang distempel —
 * optimization yang tetap dapat direkonstruksi, bukan sumber kebenaran tunggal.
 * Baris non-kas (piutang, pendapatan, beban) dan jurnal agregat yang memang
 * tidak bisa dipecah per rekening tetap NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_transaction_lines', function (Blueprint $table) {
            $table->string('subledger_type', 20)->nullable()->after('account_id');
            $table->unsignedBigInteger('subledger_id')->nullable()->after('subledger_type');
            $table->index(['subledger_type', 'subledger_id'], 'finance_transaction_lines_subledger_index');
        });
    }

    public function down(): void
    {
        Schema::table('finance_transaction_lines', function (Blueprint $table) {
            $table->dropIndex('finance_transaction_lines_subledger_index');
            $table->dropColumn(['subledger_type', 'subledger_id']);
        });
    }
};
