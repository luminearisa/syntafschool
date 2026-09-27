<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Alokasi pembayaran (§15–§18 Phase 2).
 *
 * Satu pembayaran bisa menutup SATU atau BANYAK invoice, dan satu invoice bisa
 * ditutup oleh BANYAK pembayaran. Tabel allocation adalah sumber kebenaran
 * "berapa dari pembayaran X diterapkan ke invoice Y"; `finance_invoices.paid_amount`
 * dipertahankan sebagai cache yang selalu = SUM(allocation.amount) per invoice
 * (§16: Outstanding = Total − Diskon − Alokasi terkonfirmasi − Penyesuaian).
 *
 * Jurnal double-entry TIDAK berubah karena tabel ini: piutang (AR) satu akun COA
 * (1300), jadi Dr Kas/Bank total = Cr Piutang total berapa pun invoice-nya.
 * Alokasi hanya memetakan invoice mana yang berkurang (§33 tetap traceable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('finance_payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('finance_invoices')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            // Satu pembayaran hanya boleh punya satu baris alokasi per invoice.
            $table->unique(['payment_id', 'invoice_id'], 'finance_payment_allocation_unique');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payment_allocations');
    }
};
