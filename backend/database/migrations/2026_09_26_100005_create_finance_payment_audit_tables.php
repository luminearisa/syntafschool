<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel audit pembayaran Phase 2 (§22, §35–§37, §66).
 *
 *  - `finance_payment_gateway_transactions`: jejak setiap interaksi gateway
 *    (charge dibuat, webhook diterima) beserta payload ternormalisasi dan kunci
 *    idempotensi, sehingga notifikasi ganda bisa dideteksi & ditelusuri. Bukan
 *    entitas pembayaran baru — hanya log peristiwa (§71).
 *  - `finance_payment_verifications`: riwayat lengkap tindakan admin atas
 *    payment `pending` (verifikasi/penolakan/kedaluwarsa/batal). Kolom
 *    `verified_by`/`reject_reason` di payment hanya menyimpan tindakan terakhir;
 *    tabel ini menyimpan seluruh jejak (§22, §40).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_payment_gateway_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('finance_payments')->cascadeOnDelete();
            $table->string('gateway', 40);
            $table->string('gateway_transaction_id', 120)->nullable();
            $table->string('event_type', 40);
            $table->string('status', 40)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->json('payload')->nullable();
            $table->boolean('signature_valid')->default(true);
            $table->boolean('handled')->default(false);
            $table->string('idempotency_key', 190)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['gateway', 'gateway_transaction_id']);
            $table->index('payment_id');
        });

        Schema::create('finance_payment_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('finance_payments')->cascadeOnDelete();
            $table->string('action', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payment_verifications');
        Schema::dropIfExists('finance_payment_gateway_transactions');
    }
};
