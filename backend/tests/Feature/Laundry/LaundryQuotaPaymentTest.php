<?php

declare(strict_types=1);

namespace Tests\Feature\Laundry;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryEntitlementService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryOrderService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryPaymentService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryPolicyService;
use App\Modules\SchoolOperations\Laundry\Domain\Support\OrderStatus;
use App\Modules\SchoolOperations\Laundry\Models\LaundryAuditLog;
use App\Modules\SchoolOperations\Laundry\Models\LaundryEntitlement;
use App\Modules\SchoolOperations\Laundry\Models\LaundryEntitlementTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Jatah gratis (§6–§8, §13, §14) & aturan pembayaran (§22, §56, §57).
 */
class LaundryQuotaPaymentTest extends LaundryTestCase
{
    private LaundryEntitlementService $entitlements;

    private LaundryOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(LaundryEntitlementService::class);
        $this->orders = app(LaundryOrderService::class);
    }

    // =====================================================================
    // QUOTA
    // =====================================================================

    public function test_periode_baru_membuat_entitlement_baru_dengan_ledger_allocate(): void
    {
        $student = $this->makeStudent('Joko Periode');
        $this->publishPolicy(['quota_amount' => 10]);

        $state = $this->entitlements->currentState($student->id);

        $this->assertTrue($state['has_policy']);
        $this->assertSame(10.0, $state['allocated']);
        $this->assertSame(10.0, $state['remaining']);
        $this->assertSame(now()->format('Y-m'), $state['period']['key']);
        $this->assertDatabaseHas('laundry_entitlement_transactions', [
            'student_id' => $student->id, 'transaction_type' => 'ALLOCATE', 'amount' => 10.0,
        ]);

        // Idempoten: pemanggilan berulang tidak membuat baris baru.
        $this->entitlements->currentState($student->id);
        $this->assertSame(1, LaundryEntitlement::query()->where('student_id', $student->id)->count());
    }

    public function test_carry_over_penuh_membawa_sisa_ke_periode_berikutnya(): void
    {
        $student = $this->makeStudent('Karno Carry');
        $this->publishPolicy(['quota_amount' => 10, 'carry_over_mode' => 'FULL_CARRY_OVER']);

        // Periode Oktober: pakai 7 kg → sisa 3 kg.
        $october = CarbonImmutable::parse(now()->startOfMonth()->toDateString());
        $entitlementOct = $this->entitlements->ensurePeriod($student->id, $october);
        $this->entitlements->consume($entitlementOct, 7.0, ['reason' => 'tes']);

        // Periode November: base 10 + carry 3 = 13 (§8).
        $november = $october->addMonthNoOverflow();
        $entitlementNov = $this->entitlements->ensurePeriod($student->id, $november);

        $this->assertSame(10.0, (float) $entitlementNov->allocated_quota);
        $this->assertSame(3.0, (float) $entitlementNov->carry_in_quota);
        $this->assertSame(13.0, $entitlementNov->remaining());

        // Periode lama ditutup; sisa yang terbawa TIDAK dicatat EXPIRE ganda.
        $this->assertSame('EXPIRED', $entitlementOct->fresh()->status);
        $this->assertSame(0, LaundryEntitlementTransaction::query()
            ->where('laundry_entitlement_id', $entitlementOct->id)
            ->where('transaction_type', 'EXPIRE')->count());
        $this->assertDatabaseHas('laundry_entitlement_transactions', [
            'laundry_entitlement_id' => $entitlementNov->id, 'transaction_type' => 'CARRY_IN', 'amount' => 3.0,
        ]);
    }

    public function test_carry_over_terbatas_dan_tanpa_carry_over(): void
    {
        // LIMITED: sisa 8 kg, cap 2 kg → carry 2, expire 6.
        $limited = $this->makeStudent('Lina Limited');
        $this->publishPolicy(['code' => 'POL-LIM', 'quota_amount' => 10,
            'carry_over_mode' => 'LIMITED_CARRY_OVER', 'carry_over_limit' => 2]);
        $oct = CarbonImmutable::parse(now()->startOfMonth()->toDateString());
        $entOct = $this->entitlements->ensurePeriod($limited->id, $oct);
        $this->entitlements->consume($entOct, 2.0, ['reason' => 'tes']);
        $entNov = $this->entitlements->ensurePeriod($limited->id, $oct->addMonthNoOverflow());
        $this->assertSame(2.0, (float) $entNov->carry_in_quota);
        $this->assertSame(12.0, $entNov->remaining());
        $this->assertDatabaseHas('laundry_entitlement_transactions', [
            'laundry_entitlement_id' => $entOct->id, 'transaction_type' => 'EXPIRE', 'amount' => -6.0,
        ]);

        // NO_CARRY_OVER: sisa hangus seluruhnya.
        $none = $this->makeStudent('Mira NoCarry');
        $this->publishPolicy(['code' => 'POL-NC', 'quota_amount' => 10, 'carry_over_mode' => 'NO_CARRY_OVER']);
        $entOct2 = $this->entitlements->ensurePeriod($none->id, $oct);
        $this->entitlements->consume($entOct2, 4.0, ['reason' => 'tes']);
        $entNov2 = $this->entitlements->ensurePeriod($none->id, $oct->addMonthNoOverflow());
        $this->assertSame(0.0, (float) $entNov2->carry_in_quota);
        $this->assertSame(10.0, $entNov2->remaining());
        $this->assertSame('EXPIRED', $entOct2->fresh()->status);
    }

    public function test_adjustment_wajib_beralasan_dan_teraudit(): void
    {
        $student = $this->makeStudent('Nada Adjust');
        $this->publishPolicy(['quota_amount' => 10]);
        $this->entitlements->ensurePeriod($student->id);

        // Tanpa reason → ditolak (Rule 24).
        try {
            $this->entitlements->adjust($student->id, 2.0, '  ', $this->laundryAdmin);
            $this->fail('Adjustment tanpa alasan seharusnya ditolak.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $state = $this->entitlements->adjust($student->id, 2.0, 'Special boarding policy', $this->laundryAdmin);
        $this->assertSame(12.0, $state['remaining']);

        $this->assertDatabaseHas('laundry_entitlement_transactions', [
            'student_id' => $student->id, 'transaction_type' => 'ADJUST', 'amount' => 2.0,
        ]);

        // Audit menyimpan actor/before/after/reason (§14, §75).
        $audit = LaundryAuditLog::query()->where('action', 'QUOTA_ADJUSTED')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('Special boarding policy', $audit->reason);
        $this->assertSame(10.0, (float) $audit->before['remaining']);
        $this->assertSame(12.0, (float) $audit->after['remaining']);
        $this->assertSame($this->laundryAdmin->id, $audit->actor_id);
    }

    public function test_jatah_tidak_pernah_negatif_dan_pakai_sebagian(): void
    {
        $student = $this->makeStudent('Omar Negatif');
        $this->publishPolicy(['quota_amount' => 2]);
        $entitlement = $this->entitlements->ensurePeriod($student->id);

        // Minta 5 kg dari sisa 2 kg → hanya 2 terpakai (Rule 6).
        $used = $this->entitlements->consume($entitlement, 5.0, ['reason' => 'tes']);
        $this->assertSame(2.0, $used);
        $this->assertSame(0.0, $entitlement->fresh()->remaining());
        $this->assertSame('EXHAUSTED', $entitlement->fresh()->status);

        // Konsumsi berikutnya nol.
        $usedAgain = $this->entitlements->consume($entitlement->fresh(), 3.0, ['reason' => 'tes']);
        $this->assertSame(0.0, $usedAgain);
    }

    public function test_perubahan_policy_tidak_mengubah_entitlement_historis(): void
    {
        $student = $this->makeStudent('Putri Historis');
        $version1 = $this->publishPolicy(['code' => 'POL-HIST', 'quota_amount' => 10]);
        $entitlement = $this->entitlements->ensurePeriod($student->id);
        $this->assertSame(10.0, (float) $entitlement->allocated_quota);

        // Versi baru policy (kuota naik 20 kg) dipublikasikan di periode sama.
        $policies = app(LaundryPolicyService::class);
        $policy = $version1->policy;
        $version2 = $policies->createVersion($policy, [
            'quota_type' => 'WEIGHT', 'quota_amount' => 20, 'period_type' => 'MONTHLY',
            'carry_over_mode' => 'NO_CARRY_OVER',
            'effective_from' => now()->subYear()->toDateString(),
        ], $this->laundryAdmin);
        $policies->publishVersion($version2, $this->laundryAdmin);

        // Entitlement periode berjalan tetap memakai versi & angka lama (Rule 22).
        $still = $this->entitlements->ensurePeriod($student->id);
        $this->assertSame((int) $entitlement->id, (int) $still->id);
        $this->assertSame(10.0, (float) $still->allocated_quota);
        $this->assertSame((int) $version1->id, (int) $still->laundry_policy_version_id);
    }

    // =====================================================================
    // PAYMENT RULES
    // =====================================================================

    public function test_order_belum_lunas_ditolak_masuk_pemrosesan(): void
    {
        $student = $this->makeStudent('Qori Belum Bayar');
        $this->makeWallet($student, 0);
        $this->publishPolicy(['quota_amount' => 0]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 3.0), $this->laundryAdmin);
        $this->assertSame(OrderStatus::PAYMENT_REQUIRED, $order->status);

        // Rule 27: tanpa pembayaran → tidak boleh queue.
        try {
            $this->orders->queue($order, $this->laundryAdmin);
            $this->fail('Order belum dibayar seharusnya ditolak masuk antrean.');
        } catch (\App\Modules\SchoolOperations\Laundry\Domain\Exceptions\LaundryDomainException $exception) {
            $this->assertStringContainsString('Rule 27', $exception->getMessage());
        }

        // Override FORCE_PROCESS tanpa reason → ditolak (Rule 24).
        try {
            $this->orders->override($order, 'FORCE_PROCESS', '   ', $this->laundryAdmin);
            $this->fail('Override tanpa alasan seharusnya ditolak.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        // Override beralasan → diproses, jejak audit tersimpan (§56/§57).
        $order = $this->orders->override($order, 'FORCE_PROCESS', 'Emergency: seragam upacara', $this->laundryAdmin);
        $this->assertTrue((bool) $order->allow_unpaid_processing);
        $this->orders->queue($order, $this->laundryAdmin);
        $this->assertSame(OrderStatus::QUEUED, $order->fresh()->status);

        $audit = LaundryAuditLog::query()->where('action', 'ORDER_OVERRIDE')->latest('id')->first();
        $this->assertSame('Emergency: seragam upacara', $audit->reason);
        $this->assertFalse((bool) $audit->before['allow_unpaid_processing']);
        $this->assertTrue((bool) $audit->after['allow_unpaid_processing']);
    }

    public function test_waive_fee_membebaskan_tagihan_dengan_jejak_audit(): void
    {
        $student = $this->makeStudent('Rina Waive');
        $this->makeWallet($student, 0);
        $this->publishPolicy(['quota_amount' => 0]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 2.0), $this->laundryAdmin);
        $this->assertSame(20000.0, (float) $order->total_amount);

        $order = $this->orders->override($order, 'WAIVE_FEE', 'Kompensasi keterlambatan laundry', $this->laundryAdmin);

        $this->assertSame('WAIVED', $order->payment_status);
        $this->assertSame(0.0, $order->payableAmount());
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->orders->queue($order, $this->laundryAdmin); // tidak melempar
        $this->assertSame(OrderStatus::QUEUED, $order->fresh()->status);
    }

    public function test_kunci_idempotensi_berbeda_tetap_tidak_double_charge_setelah_lunas(): void
    {
        $student = $this->makeStudent('Sari Idem');
        $wallet = $this->makeWallet($student, 100000);
        $this->publishPolicy(['quota_amount' => 0]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 2.0), $this->laundryAdmin);
        $payments = app(LaundryPaymentService::class);

        $first = $payments->pay($order, 'key-A', $this->laundryAdmin);
        $this->assertTrue($first['success']);

        // Kunci berbeda setelah order lunas → replay pembayaran yang ada.
        $second = $payments->pay($order->fresh(), 'key-B', $this->laundryAdmin);
        $this->assertTrue($second['success']);
        $this->assertTrue($second['replayed'] ?? false);

        $this->assertSame(80000.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, \App\Models\WalletTransaction::query()
            ->where('type', \App\Models\WalletTransaction::TYPE_WITHDRAW)->count());
    }

    public function test_transaksi_jatah_membentuk_rantai_balance_after(): void
    {
        $student = $this->makeStudent('Tono Rantai');
        $this->publishPolicy(['quota_amount' => 10]);
        $entitlement = $this->entitlements->ensurePeriod($student->id);

        $this->entitlements->consume($entitlement, 4.0, ['reason' => 'order 1']);
        $this->entitlements->consume($entitlement->fresh(), 3.5, ['reason' => 'order 2']);
        $this->entitlements->adjust($student->id, -1.0, 'Koreksi administrasi', $this->laundryAdmin);

        $rows = LaundryEntitlementTransaction::query()
            ->where('student_id', $student->id)
            ->orderBy('id')
            ->get(['transaction_type', 'amount', 'balance_after']);

        $this->assertSame(['ALLOCATE', 'USE', 'USE', 'ADJUST'], $rows->pluck('transaction_type')->all());
        $this->assertSame([10.0, 6.0, 2.5, 1.5], array_map('floatval', $rows->pluck('balance_after')->all()));
        $this->assertSame(1.5, $entitlement->fresh()->remaining());
    }
}
