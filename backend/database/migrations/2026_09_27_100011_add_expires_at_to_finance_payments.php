<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 5 §7: batas waktu pembayaran gateway/VA.
 *
 * Pembayaran lewat gateway lahir dengan masa berlaku — provider mengirim
 * `expires_at` — tetapi angka itu hanya lewat di respons API lalu hilang.
 * Akibatnya `pending` milik kanal yang sudah mati tidak pernah bisa kedaluwarsa
 * sendiri: penagihan dan portal membacanya sebagai "sedang dibayar" selamanya,
 * dan wali tidak pernah disuruh mengulang. Kolom ini menjadi pegangan scheduler
 * (`WAITING_PAYMENT → EXPIRED`) sekaligus dasar daftar "menunggu pembayaran".
 *
 * Nullable dan tanpa backfill: pembayaran lama tidak boleh dianggap kedaluwarsa
 * berdasarkan tanggal perkiraan, dan pembayaran transfer manual yang menunggu
 * verifikasi petugas memang tidak punya batas waktu kanal — yang punya hanya baris
 * yang kanal pembayarannya sendiri sudah menutup diri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('paid_at');
            // Sapuan harian membaca (status pending, batas waktu lewat): indeks
            // gabungan supaya tabel pembayaran yang besar tidak dipindai penuh.
            $table->index(['status', 'expires_at'], 'finance_payments_status_expires_index');
        });
    }

    public function down(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->dropIndex('finance_payments_status_expires_index');
            $table->dropColumn('expires_at');
        });
    }
};
