<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 5 §4: idempotensi pada pencatatan pembayaran.
 *
 * Pindah uang dan catat pembayaran punya bentuk kegagalan yang sama: tombol
 * ganda atau retry jaringan melahirkan DUA pembayaran sah atas satu slip yang
 * sama — dua jurnal, dua kuitansi, dan sisa tagihan yang bergerak dua kali.
 * Mesin pembayaran tidak bisa menebak mana yang dimaksud, jadi kunci ini
 * dipasok pemanggil (nomor slip, referensi kasir, id request gateway) dan
 * keunikannya ditegakkan oleh database, bukan oleh doa.
 *
 * Polanya disengaja sama persis dengan `finance_transfers` (Phase 4 §3): unik
 * per (pembuat, kunci). Tanpa pengikatan itu, dua kasir berbeda yang memakai
 * teks kunci sama ("SETORAN-1") akan saling menahan pembayaran yang sah; dengan
 * pengikatan itu, permintaan ulang dari sumber yang sama tetap menghasilkan
 * baris yang sama.
 *
 * Kolomnya nullable dan tanpa backfill: pembayaran yang sudah tercatat sebelum
 * kunci ini ada tidak punya kunci, dan mengarang kunci dari `reference_number`
 * akan membuat pembayaran ulang yang sah (mis. dua transfer bank dengan
 * referensi sama) tertolak diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('payment_number');
            $table->unique(['created_by', 'idempotency_key'], 'finance_payments_created_by_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->dropUnique('finance_payments_created_by_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
