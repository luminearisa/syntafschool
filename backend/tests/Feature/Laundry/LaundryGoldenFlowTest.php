<?php

declare(strict_types=1);

namespace Tests\Feature\Laundry;

use App\Models\WalletTransaction;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryDeliveryService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryOrderService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryPaymentService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryProcessingService;
use App\Modules\SchoolOperations\Laundry\Domain\Support\OrderStatus;
use App\Modules\SchoolOperations\Laundry\Models\LaundryEntitlementTransaction;
use App\Modules\SchoolOperations\Laundry\Models\LaundryNotification;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrderAllocation;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPayment;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPaymentAttempt;

/**
 * Golden flows (§66–§70): gratis penuh, jatah sebagian, jatah habis, saldo
 * tidak cukup, dan pembayaran RFID — end-to-end sampai COMPLETED.
 */
class LaundryGoldenFlowTest extends LaundryTestCase
{
    private LaundryOrderService $orders;

    private LaundryPaymentService $payments;

    private LaundryProcessingService $processing;

    private LaundryDeliveryService $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = app(LaundryOrderService::class);
        $this->payments = app(LaundryPaymentService::class);
        $this->processing = app(LaundryProcessingService::class);
        $this->delivery = app(LaundryDeliveryService::class);
    }

    /** §66: seluruh order tertutup jatah gratis — tanpa pembayaran. */
    public function test_golden_flow_gratis_penuh_sampai_selesai(): void
    {
        $student = $this->makeStudent('Ahmad Gratis');
        $this->makeWallet($student, 100000, 'RFID-AHMAD');
        $this->publishPolicy(['quota_amount' => 10]);
        $this->pricePerKg(null, 10000);

        $order = $this->weighedOrder($student, 5.0);
        $order = $this->orders->calculate($order, $this->laundryAdmin);

        $this->assertSame(5.0, (float) $order->free_quantity);
        $this->assertSame(0.0, (float) $order->paid_quantity);
        $this->assertSame(0.0, (float) $order->total_amount);
        $this->assertSame('NOT_REQUIRED', $order->payment_status);
        $this->assertSame(OrderStatus::PAID, $order->status);

        // Jatah 10 → 5 dengan ledger USE.
        $state = app(\App\Modules\SchoolOperations\Laundry\Application\Services\LaundryEntitlementService::class)
            ->currentState($student->id);
        $this->assertSame(5.0, $state['remaining']);
        $this->assertDatabaseHas('laundry_entitlement_transactions', [
            'student_id' => $student->id, 'transaction_type' => 'USE', 'amount' => -5.0,
        ]);

        // Proses sampai selesai lewat batch per tahap.
        $this->orders->queue($order, $this->laundryAdmin);
        foreach (['WASHING', 'DRYING', 'IRONING', 'FOLDING'] as $stage) {
            $batch = $this->processing->createBatch(['stage' => $stage], $this->laundryAdmin);
            $this->processing->addOrders($batch, [$order->id], $this->laundryAdmin);
            $this->processing->startBatch($batch->fresh(), $this->laundryAdmin);
            $this->processing->completeBatch($batch->fresh(), $this->laundryAdmin);
            $order = $order->fresh();
        }
        $this->assertSame(OrderStatus::QUALITY_CHECK, $order->status);

        $this->processing->qualityCheck($order, [
            'result' => 'PASSED',
            'checklist' => ['clean' => true, 'dry' => true, 'complete_items' => true, 'correct_student' => true],
        ], $this->laundryAdmin);
        $order = $this->processing->package($order->fresh(), [], $this->laundryAdmin);
        $this->delivery->markReady($order->fresh(), $this->laundryAdmin);

        // Pengambilan dengan verifikasi RFID santri.
        $result = $this->delivery->pickup($order->fresh(), [
            'verification_method' => 'RFID',
            'verification_value' => 'RFID-AHMAD',
            'receiver_name' => 'Ahmad Gratis',
        ], $this->laundryAdmin);

        $this->assertSame(OrderStatus::COMPLETED, $result['order']->status);
        $this->assertNotNull($result['order']->completed_at);

        // Wallet tidak tersentuh sama sekali.
        $this->assertSame(100000.0, (float) \App\Models\Wallet::query()
            ->where('student_id', $student->id)->first()->balance);
        $this->assertSame(0, LaundryPayment::query()->where('laundry_order_id', $order->id)->count());

        // Notifikasi wali terkirim (diterima, diproses, siap, dikembalikan).
        foreach (['ORDER_RECEIVED', 'PROCESSING', 'READY', 'DELIVERED'] as $type) {
            $this->assertDatabaseHas('laundry_notifications', [
                'laundry_order_id' => $order->id, 'notification_type' => $type,
            ]);
        }
    }

    /** §67: jatah 3 kg, order 5 kg → free 3 + paid 2 = Rp20.000 via wallet. */
    public function test_golden_flow_jatah_sebagian_split_free_dan_paid(): void
    {
        $student = $this->makeStudent('Budi Sebagian');
        $wallet = $this->makeWallet($student, 100000);
        $this->publishPolicy(['quota_amount' => 3]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 5.0), $this->laundryAdmin);

        $this->assertSame(3.0, (float) $order->free_quantity);
        $this->assertSame(2.0, (float) $order->paid_quantity);
        $this->assertSame(20000.0, (float) $order->total_amount);
        $this->assertSame(OrderStatus::PAYMENT_REQUIRED, $order->status);
        $this->assertSame('PAYMENT_REQUIRED', $order->payment_status);

        // Split eksplisit sebagai baris alokasi (§12).
        $this->assertSame(3.0, (float) LaundryOrderAllocation::query()
            ->where('laundry_order_id', $order->id)->where('allocation_type', 'FREE')->value('quantity'));
        $this->assertSame(20000.0, (float) LaundryOrderAllocation::query()
            ->where('laundry_order_id', $order->id)->where('allocation_type', 'PAID')->value('amount'));

        $result = $this->payments->pay($order, 'key-sebagian-1', $this->laundryAdmin);

        $this->assertTrue($result['success']);
        $this->assertSame(20000.0, $result['payment']['amount']);
        $this->assertSame(100000.0, $result['payment']['balance_before']);
        $this->assertSame(80000.0, $result['payment']['balance_after']);
        $this->assertNotNull($result['payment']['wallet_transaction_number']);

        $order = $order->fresh();
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertSame('PAID', $order->payment_status);
        $this->assertSame(80000.0, (float) $wallet->fresh()->balance);

        // Sisa jatah habis → 0.
        $state = app(\App\Modules\SchoolOperations\Laundry\Application\Services\LaundryEntitlementService::class)
            ->currentState($student->id);
        $this->assertSame(0.0, $state['remaining']);

        $this->assertDatabaseHas('laundry_notifications', [
            'laundry_order_id' => $order->id, 'notification_type' => 'PAYMENT_COMPLETED',
        ]);
    }

    /** §68: jatah habis → seluruh 4 kg berbayar; PAID hanya setelah charge sukses. */
    public function test_golden_flow_jatah_habis_semua_berbayar(): void
    {
        $student = $this->makeStudent('Candra Habis');
        $wallet = $this->makeWallet($student, 100000);
        $this->publishPolicy(['quota_amount' => 0]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 4.0), $this->laundryAdmin);

        $this->assertSame(0.0, (float) $order->free_quantity);
        $this->assertSame(40000.0, (float) $order->total_amount);
        $this->assertSame(OrderStatus::PAYMENT_REQUIRED, $order->status);

        $result = $this->payments->pay($order, 'key-habis-1', $this->laundryAdmin);

        $this->assertTrue($result['success']);
        $this->assertSame(60000.0, (float) $wallet->fresh()->balance);
        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->wallet_transaction_number);
    }

    /** §69: saldo 20k < tagihan 30k → PAYMENT_REQUIRED, tanpa double charge. */
    public function test_golden_flow_saldo_tidak_cukup_tanpa_double_charge(): void
    {
        $student = $this->makeStudent('Dedi Kurang');
        $wallet = $this->makeWallet($student, 20000);
        $this->publishPolicy(['quota_amount' => 2]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 5.0), $this->laundryAdmin);
        $this->assertSame(30000.0, (float) $order->total_amount);

        $first = $this->payments->pay($order, 'key-kurang-1', $this->laundryAdmin);
        $this->assertFalse($first['success']);
        $this->assertSame('INSUFFICIENT_BALANCE', $first['reason']);

        // Rule 12: gagal ≠ paid; saldo tidak berubah; order tetap menunggu.
        $order = $order->fresh();
        $this->assertSame(OrderStatus::PAYMENT_REQUIRED, $order->status);
        $this->assertSame('PAYMENT_REQUIRED', $order->payment_status);
        $this->assertSame(20000.0, (float) $wallet->fresh()->balance);
        $this->assertSame(0, WalletTransaction::query()->where('type', WalletTransaction::TYPE_WITHDRAW)->count());

        // Retry dengan kunci sama maupun berbeda tetap tidak memotong saldo.
        $retry = $this->payments->pay($order, 'key-kurang-1', $this->laundryAdmin);
        $this->assertFalse($retry['success']);
        $this->assertSame(20000.0, (float) $wallet->fresh()->balance);

        // Wali menerima notifikasi saldo tidak cukup (§69).
        $this->assertDatabaseHas('laundry_notifications', [
            'laundry_order_id' => $order->id, 'notification_type' => 'INSUFFICIENT_BALANCE',
        ]);

        // Setelah top up, pembayaran kunci lama berhasil tepat satu kali.
        app(\App\Services\Wallet\WalletLedgerService::class)
            ->post($wallet, WalletTransaction::TYPE_TOPUP, 30000, ['description' => 'Top up wali']);

        $paid = $this->payments->pay($order->fresh(), 'key-kurang-1', $this->laundryAdmin);
        $this->assertTrue($paid['success']);
        $this->assertSame(20000.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, LaundryPayment::query()->where('laundry_order_id', $order->id)->count());
    }

    /** §70: tap RFID → charge wallet; kartu santri lain ditolak. */
    public function test_golden_flow_pembayaran_rfid(): void
    {
        $student = $this->makeStudent('Eka RFID');
        $other = $this->makeStudent('Faisal Lain');
        $wallet = $this->makeWallet($student, 100000, 'UID-EKA');
        $this->makeWallet($other, 50000, 'UID-FAISAL');
        $this->publishPolicy(['quota_amount' => 0]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 2.0), $this->laundryAdmin);

        // Kartu santri lain ditolak.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            $this->payments->pay($order, 'key-rfid-salah', $this->laundryAdmin, 'WALLET_RFID', 'UID-FAISAL');
        } finally {
            $this->assertSame(100000.0, (float) $wallet->fresh()->balance);
        }
    }

    public function test_pembayaran_rfid_sukses_memotong_wallet_sendiri(): void
    {
        $student = $this->makeStudent('Eka RFID');
        $wallet = $this->makeWallet($student, 100000, 'UID-EKA');
        $this->publishPolicy(['quota_amount' => 0]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 2.0), $this->laundryAdmin);

        $result = $this->payments->pay($order, 'key-rfid-1', $this->laundryAdmin, 'WALLET', 'UID-EKA');

        $this->assertTrue($result['success']);
        $this->assertSame('WALLET_RFID', $result['payment']['method']);
        $this->assertSame(80000.0, (float) $wallet->fresh()->balance);

        // Idempoten: replay kunci sama tidak memotong lagi.
        $replay = $this->payments->pay($order->fresh(), 'key-rfid-1', $this->laundryAdmin, 'WALLET', 'UID-EKA');
        $this->assertTrue($replay['success']);
        $this->assertTrue($replay['replayed'] ?? false);
        $this->assertSame(80000.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransaction::TYPE_WITHDRAW)->count());
        $this->assertSame(1, LaundryPaymentAttempt::query()->where('laundry_order_id', $order->id)->count());
    }

    /** §23: pembatalan setelah bayar → refund wallet + jatah dikembalikan. */
    public function test_pembatalan_mengembalikan_dana_dan_jatah(): void
    {
        $student = $this->makeStudent('Gina Batal');
        $wallet = $this->makeWallet($student, 100000);
        $this->publishPolicy(['quota_amount' => 3]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 5.0), $this->laundryAdmin);
        $this->payments->pay($order, 'key-batal-1', $this->laundryAdmin);
        $this->assertSame(80000.0, (float) $wallet->fresh()->balance);

        $result = $this->orders->cancel($order->fresh(), 'Santri menarik kembali laundry', $this->laundryAdmin);

        $this->assertSame(100000.0, (float) $wallet->fresh()->balance);
        $this->assertSame(3.0, $result['quota_reversed']);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame('REFUNDED', $order->fresh()->payment_status);
        $this->assertDatabaseHas('wallet_transactions', ['type' => WalletTransaction::TYPE_REFUND]);

        // Jatah kembali: ledger REVERSE +3 dan remaining pulih.
        $this->assertDatabaseHas('laundry_entitlement_transactions', [
            'student_id' => $student->id, 'transaction_type' => 'REVERSE', 'amount' => 3.0,
        ]);
        $state = app(\App\Modules\SchoolOperations\Laundry\Application\Services\LaundryEntitlementService::class)
            ->currentState($student->id);
        $this->assertSame(3.0, $state['remaining']);
    }

    /** Rule 21: setiap pemakaian jatah punya baris ledger bertaut alokasi. */
    public function test_pemakaian_jatah_selalu_punya_baris_ledger(): void
    {
        $student = $this->makeStudent('Hasan Ledger');
        $this->makeWallet($student, 0);
        $this->publishPolicy(['quota_amount' => 10]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 4.0), $this->laundryAdmin);

        $freeAllocation = LaundryOrderAllocation::query()
            ->where('laundry_order_id', $order->id)
            ->where('allocation_type', 'FREE')
            ->first();

        $this->assertNotNull($freeAllocation->entitlement_transaction_id);
        $ledger = LaundryEntitlementTransaction::query()->find($freeAllocation->entitlement_transaction_id);
        $this->assertSame('USE', $ledger->transaction_type);
        $this->assertSame(-4.0, (float) $ledger->amount);
        $this->assertSame(6.0, (float) $ledger->balance_after);
    }

    /** Notifikasi pembayaran kurang saldo memuat nominal & saldo (§69). */
    public function test_notifikasi_insufficient_memuat_nominal_dan_saldo(): void
    {
        $student = $this->makeStudent('Iwan Notif');
        $this->makeWallet($student, 20000);
        $this->publishPolicy(['quota_amount' => 0]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 3.0), $this->laundryAdmin);
        $this->payments->pay($order, 'key-notif-1', $this->laundryAdmin);

        $notification = LaundryNotification::query()
            ->where('laundry_order_id', $order->id)
            ->where('notification_type', 'INSUFFICIENT_BALANCE')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Rp 30.000', $notification->body);
        $this->assertStringContainsString('Rp 20.000', $notification->body);
    }
}
