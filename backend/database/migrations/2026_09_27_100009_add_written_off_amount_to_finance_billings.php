<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Write-off piutang harus bisa dihitung ulang, bukan hanya dikurangkan sekali.
 *
 * Invoice sudah punya `written_off_amount`; billing induknya tidak, sehingga
 * `FinanceAdjustmentService` dulu mengurangi `remaining_amount` billing begitu
 * saja. Angka hasil kurang itu tidak bisa diturunkan dari kolom mana pun, jadi
 * perhitungan ulang berikutnya (pembayaran masuk, recalculate billing) langsung
 * memakainya sebagai `total − paid` dan write-off pun hidup kembali sebagai
 * utang. Kolom ini membuat invariant §31 berlaku di kedua dokumen:
 * outstanding = total − pembayaran − write-off.
 *
 * Default 0 dan tanpa backfill: write-off yang sudah terposting sebelum kolom ini
 * ada tetap terbaca di invoice-nya, dan `FinanceOutstandingRecalculator` hanya
 * dipakai saat ada perhitungan ulang berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_billings', function (Blueprint $table) {
            $table->decimal('written_off_amount', 15, 2)->default(0)->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('finance_billings', function (Blueprint $table) {
            $table->dropColumn('written_off_amount');
        });
    }
};
