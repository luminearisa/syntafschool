<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran wallet (§19–§23), refund (§23), serah terima (§43/§44),
 * notifikasi (§45), dan audit log modul (§75).
 *
 * Idempotensi (Rule 11/25): unik (laundry_order_id, idempotency_key) pada
 * attempt + unik wallet_transaction_id pada payment — retry jaringan tidak
 * pernah memotong saldo dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->cascadeOnDelete();
            // Kunci idempotensi: laundry_order_id + payment_attempt_id (§22).
            $table->string('idempotency_key', 100);
            $table->unsignedInteger('attempt_number')->default(1);
            // PENDING / SUCCESS / FAILED.
            $table->string('status', 20)->default('PENDING');
            $table->decimal('amount', 14, 2);
            $table->string('failure_reason')->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->string('wallet_transaction_number', 50)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['laundry_order_id', 'idempotency_key']);
            $table->index(['laundry_order_id', 'status']);
        });

        Schema::create('laundry_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 50)->unique();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->restrictOnDelete();
            $table->foreignId('laundry_payment_attempt_id')->nullable()
                ->constrained('laundry_payment_attempts')->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            // Rule 13: referensi transaksi wallet wajib ada utk pembayaran sah.
            $table->foreignId('wallet_transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();
            $table->string('wallet_transaction_number', 50)->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_before', 14, 2)->nullable();
            $table->decimal('balance_after', 14, 2)->nullable();
            // WALLET / WALLET_RFID / MANUAL.
            $table->string('method', 20)->default('WALLET');
            // PAID / FAILED / REFUNDED.
            $table->string('status', 20)->default('PAID');
            // Kartu RFID yang dipakai saat tap (§70) — credential, bukan saldo.
            $table->string('rfid_uid', 64)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('laundry_order_id');
            $table->unique('wallet_transaction_id');
        });

        Schema::create('laundry_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number', 50)->unique();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->restrictOnDelete();
            $table->foreignId('laundry_payment_id')->nullable()
                ->constrained('laundry_payments')->nullOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();
            $table->string('wallet_transaction_number', 50)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('reason');
            // PENDING / COMPLETED / FAILED.
            $table->string('status', 20)->default('COMPLETED');
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('laundry_order_id');
        });

        Schema::create('laundry_pickups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->restrictOnDelete();
            // Pengambilan di counter (§43): verifikasi penerima wajib (Rule 19).
            $table->string('receiver_name', 100);
            // RFID / QR / PIN / STUDENT_ID / AUTHORIZED_PERSON (§44).
            $table->string('verification_method', 20);
            $table->string('verification_value', 100)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('picked_up_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('laundry_order_id');
        });

        Schema::create('laundry_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained('laundry_orders')->restrictOnDelete();
            // Pengantaran ke asrama (§43).
            $table->foreignId('dormitory_id')->nullable()
                ->constrained('boarding_dormitories')->nullOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receiver_name', 100);
            $table->string('verification_method', 20);
            $table->string('verification_value', 100)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('laundry_order_id');
        });

        Schema::create('laundry_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->nullable()
                ->constrained('laundry_orders')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('notification_type', 40);
            $table->string('title');
            $table->text('body');
            // User id penerima (wali/santri) saat dikirim.
            $table->json('recipients')->nullable();
            $table->boolean('sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->string('provider', 30)->nullable();
            $table->json('payload')->nullable();
            // Dedupe: satu order+tipe hanya sekali (kecuali reminder).
            $table->string('dedupe_key', 120)->nullable()->unique();
            $table->timestamps();

            $table->index('laundry_order_id');
            $table->index(['student_id', 'notification_type']);
        });

        Schema::create('laundry_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // ORDER_CREATED, STATUS_CHANGED, QUOTA_ADJUSTED, PAYMENT_CHARGED,
            // OVERRIDE, WEIGHT_CHANGED, REFUND, INCIDENT, DELIVERY, PRICING_*…
            $table->string('action', 60);
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            // Rule 24: override/adjustment wajib beralasan.
            $table->string('reason')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_audit_logs');
        Schema::dropIfExists('laundry_notifications');
        Schema::dropIfExists('laundry_deliveries');
        Schema::dropIfExists('laundry_pickups');
        Schema::dropIfExists('laundry_refunds');
        Schema::dropIfExists('laundry_payments');
        Schema::dropIfExists('laundry_payment_attempts');
    }
};
