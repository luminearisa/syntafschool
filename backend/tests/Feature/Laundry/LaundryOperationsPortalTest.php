<?php

declare(strict_types=1);

namespace Tests\Feature\Laundry;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryBagService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryCatalogService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryDeliveryService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryIncidentService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryOrderService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryPaymentService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryProcessingService;
use App\Modules\SchoolOperations\Laundry\Domain\Support\OrderStatus;
use App\Modules\SchoolOperations\Laundry\Models\LaundryBag;
use App\Modules\SchoolOperations\Laundry\Models\LaundryLabel;
use App\Modules\SchoolOperations\Laundry\Models\LaundryProcessingLog;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/**
 * Operasi (menerima, menimbang, memproses, QC, mengemas, mengirim — §76) dan
 * portal wali/santri (otorisasi anak, visibilitas jatah/order/pembayaran).
 */
class LaundryOperationsPortalTest extends LaundryTestCase
{
    private LaundryOrderService $orders;

    private LaundryProcessingService $processing;

    private LaundryDeliveryService $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = app(LaundryOrderService::class);
        $this->processing = app(LaundryProcessingService::class);
        $this->delivery = app(LaundryDeliveryService::class);
    }

    // =====================================================================
    // OPERASI
    // =====================================================================

    public function test_penerimaan_membuat_label_dan_timbangan_netto(): void
    {
        $student = $this->makeStudent('Umar Terima');
        $this->publishPolicy(['quota_amount' => 0]);
        $this->pricePerKg();

        $bag = app(LaundryBagService::class)->create([
            'bag_code' => 'BAG-001', 'rfid_uid' => 'BAGUID-1', 'tare_weight' => 0.5,
        ], $this->laundryAdmin);
        app(LaundryBagService::class)->assign($bag, $student->id, $this->laundryAdmin);

        $order = $this->orders->create([
            'student_id' => $student->id,
            'laundry_service_id' => $this->regularService()->id,
            'items' => [
                ['item_type' => 'SHIRT', 'quantity' => 3],
                ['item_type' => 'PANTS', 'quantity' => 2],
            ],
        ], $this->laundryAdmin);

        $order = $this->orders->receive($order->fresh(), ['bag_code' => 'BAG-001'], $this->laundryAdmin);

        $this->assertSame(OrderStatus::RECEIVED, $order->status);
        $this->assertSame(5, $order->item_count);
        $this->assertNotNull($order->due_at);
        $this->assertSame('IN_USE', $bag->fresh()->status);

        // Label digital dengan QR payload (§31).
        $label = LaundryLabel::query()->where('laundry_order_id', $order->id)->first();
        $this->assertNotNull($label);
        $payload = json_decode($label->qr_payload, true);
        $this->assertSame($order->order_number, $payload['order_number']);
        $this->assertSame('BAG-001', $payload['bag_code']);

        // Netto = gross − berat tas (§30); pengukuran mentah tersimpan.
        $order = $this->orders->weigh($order->fresh(), ['gross_weight' => 5.5], $this->laundryAdmin);
        $this->assertSame(5.5, (float) $order->gross_weight);
        $this->assertSame(0.5, (float) $order->bag_weight);
        $this->assertSame(5.0, (float) $order->net_weight);
    }

    public function test_batch_qc_kemasan_dan_pengantaran_asrama(): void
    {
        $student = $this->makeStudent('Vina Proses');
        $this->makeWallet($student, 0);
        $this->publishPolicy(['quota_amount' => 10]);
        $this->pricePerKg();

        $order = $this->orders->calculate($this->weighedOrder($student, 4.0), $this->laundryAdmin);
        $this->orders->queue($order, $this->laundryAdmin);

        // Batch cuci: mulai → order WASHING; selesai → order DRYING (§33).
        $batch = $this->processing->createBatch(['stage' => 'WASHING'], $this->laundryAdmin);
        $this->processing->addOrders($batch, [$order->id], $this->laundryAdmin);
        $this->processing->startBatch($batch->fresh(), $this->laundryAdmin);
        $this->assertSame(OrderStatus::WASHING, $order->fresh()->status);
        $this->processing->completeBatch($batch->fresh(), $this->laundryAdmin);
        $this->assertSame(OrderStatus::DRYING, $order->fresh()->status);
        $this->assertSame(4.0, (float) $batch->fresh()->total_weight);

        // Langkah individual sampai QC (start + complete per tahap).
        foreach (['DRYING', 'IRONING', 'FOLDING'] as $stage) {
            $this->processing->recordStep($order->fresh(), $stage, 'start', [], $this->laundryAdmin);
            $this->processing->recordStep($order->fresh(), $stage, 'complete', [], $this->laundryAdmin);
        }
        $this->assertSame(OrderStatus::QUALITY_CHECK, $order->fresh()->status);

        // QC gagal → kembali cuci; QC ulang lulus → kemasan (§38).
        $this->processing->qualityCheck($order->fresh(), ['result' => 'FAILED', 'notes' => 'Masih bernoda'], $this->laundryAdmin);
        $this->assertSame(OrderStatus::WASHING, $order->fresh()->status);

        // Jalankan ulang rantai sampai QC.
        foreach (['WASHING', 'DRYING', 'IRONING', 'FOLDING'] as $stage) {
            $rebatch = $this->processing->createBatch(['stage' => $stage], $this->laundryAdmin);
            $this->processing->addOrders($rebatch, [$order->fresh()->id], $this->laundryAdmin);
            $this->processing->startBatch($rebatch->fresh(), $this->laundryAdmin);
            $this->processing->completeBatch($rebatch->fresh(), $this->laundryAdmin);
        }
        $this->assertSame(OrderStatus::QUALITY_CHECK, $order->fresh()->status);

        // Kemasan tanpa QC lulus ditolak.
        $qc = $this->processing->qualityCheck($order->fresh(), [
            'result' => 'PASSED',
            'checklist' => ['clean' => true, 'dry' => true, 'no_odor' => true, 'no_stain' => true,
                'complete_items' => true, 'correct_student' => true, 'proper_folding' => true, 'proper_packaging' => true],
        ], $this->laundryAdmin);
        $this->assertSame('PASSED', $qc->result);

        $order = $this->processing->package($order->fresh(), [], $this->laundryAdmin);
        $this->assertSame(OrderStatus::PACKAGED, $order->status);

        // Ready → delivery asrama dengan verifikasi QR order.
        $this->delivery->markReady($order->fresh(), $this->laundryAdmin);
        $result = $this->delivery->deliver($order->fresh(), [
            'verification_method' => 'QR',
            'verification_value' => $order->order_number,
            'receiver_name' => 'Musyrif Asrama',
        ], $this->laundryAdmin);

        $this->assertSame(OrderStatus::COMPLETED, $result['order']->status);
        $this->assertDatabaseHas('laundry_deliveries', [
            'laundry_order_id' => $order->id, 'receiver_name' => 'Musyrif Asrama',
        ]);

        // Traceability penuh: log transisi mencatat seluruh rantai (Rule 28).
        $statuses = LaundryProcessingLog::query()
            ->where('laundry_order_id', $order->id)
            ->orderBy('id')
            ->pluck('to_status')
            ->all();
        foreach ([OrderStatus::RECEIVED, OrderStatus::WEIGHED, OrderStatus::PAID, OrderStatus::QUEUED,
            OrderStatus::WASHING, OrderStatus::DRYING, OrderStatus::QUALITY_CHECK, OrderStatus::PACKAGED,
            OrderStatus::READY, OrderStatus::DELIVERED, OrderStatus::COMPLETED] as $expected) {
            $this->assertContains($expected, $statuses);
        }
    }

    public function test_verifikasi_penerima_yang_salah_ditolak(): void
    {
        $student = $this->makeStudent('Wati Verif');
        $other = $this->makeStudent('Yanto Lain');
        $this->makeWallet($student, 0, 'UID-WATI');
        $this->makeWallet($other, 0, 'UID-YANTO');
        $this->publishPolicy(['quota_amount' => 10]);
        $this->pricePerKg();

        $order = $this->orders->calculate($this->weighedOrder($student, 2.0), $this->laundryAdmin);
        $this->orders->queue($order, $this->laundryAdmin);
        $batch = $this->processing->createBatch(['stage' => 'WASHING'], $this->laundryAdmin);
        $this->processing->addOrders($batch, [$order->id], $this->laundryAdmin);
        $this->processing->startBatch($batch->fresh(), $this->laundryAdmin);
        $this->processing->completeBatch($batch->fresh(), $this->laundryAdmin);
        foreach (['DRYING', 'IRONING', 'FOLDING'] as $stage) {
            $this->processing->recordStep($order->fresh(), $stage, 'start', [], $this->laundryAdmin);
            $this->processing->recordStep($order->fresh(), $stage, 'complete', [], $this->laundryAdmin);
        }
        $this->processing->qualityCheck($order->fresh(), ['result' => 'PASSED', 'checklist' => ['clean' => true]], $this->laundryAdmin);
        $this->processing->package($order->fresh(), [], $this->laundryAdmin);
        $this->delivery->markReady($order->fresh(), $this->laundryAdmin);

        // RFID santri lain ditolak (Rule 19).
        try {
            $this->delivery->pickup($order->fresh(), [
                'verification_method' => 'RFID', 'verification_value' => 'UID-YANTO',
                'receiver_name' => 'Penipu',
            ], $this->laundryAdmin);
            $this->fail('RFID santri lain seharusnya ditolak.');
        } catch (ValidationException) {
            $this->assertSame(OrderStatus::READY, $order->fresh()->status);
        }

        // Tanpa nama penerima ditolak.
        try {
            $this->delivery->pickup($order->fresh(), [
                'verification_method' => 'RFID', 'verification_value' => 'UID-WATI', 'receiver_name' => '',
            ], $this->laundryAdmin);
            $this->fail('Nama penerima wajib.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_insiden_item_hilang_dan_kompensasi_berapproval(): void
    {
        $student = $this->makeStudent('Zaki Insiden');
        $wallet = $this->makeWallet($student, 50000);
        $this->publishPolicy(['quota_amount' => 0]);
        $this->pricePerKg();

        $order = $this->orders->calculate($this->weighedOrder($student, 2.0), $this->laundryAdmin);
        app(LaundryPaymentService::class)->pay($order, 'key-inc-1', $this->laundryAdmin);

        $incidents = app(LaundryIncidentService::class);
        $incident = $incidents->report($order->fresh(), [
            'incident_type' => 'MISSING',
            'description' => 'Satu jaket tidak ditemukan setelah pencucian',
        ], $this->laundryAdmin);

        // Rule 18: item hilang → order LOST.
        $this->assertSame(OrderStatus::LOST, $order->fresh()->status);

        // Kompensasi belum dieksekusi sebelum approval (§41): saldo tetap
        // 50.000 − 20.000 pembayaran = 30.000.
        $incident = $incidents->proposeCompensation($incident, 'WALLET_REFUND', 50000, $this->laundryAdmin);
        $this->assertSame(30000.0, (float) $wallet->fresh()->balance);

        $incident = $incidents->approveCompensation($incident->fresh(), $this->laundryAdmin, 'Persetujuan manajer');
        $this->assertSame('RESOLVED', $incident->status);
        $this->assertNotNull($incident->compensation_reference);
        $this->assertSame(80000.0, (float) $wallet->fresh()->balance); // 50k − 20k bayar + 50k kompensasi
    }

    // =====================================================================
    // PORTAL WALI & SANTRI
    // =====================================================================

    public function test_wali_melihat_jatah_order_dan_pembayaran_anaknya_saja(): void
    {
        $child = $this->makeStudent('Anak Sah');
        $stranger = $this->makeStudent('Anak Orang');
        $wallet = $this->makeWallet($child, 100000);
        $this->publishPolicy(['quota_amount' => 10]);
        $this->pricePerKg();

        $order = $this->orders->calculate($this->weighedOrder($child, 12.0), $this->laundryAdmin);
        app(LaundryPaymentService::class)->pay($order->fresh(), 'key-portal-1', $this->laundryAdmin);

        $parent = $this->makeParentRoleUser($this->makeGuardianUserFor($child));
        Sanctum::actingAs($parent, ['*']);

        // Overview anak: jatah, terpakai, sisa, saldo wallet dari provider (§71/§47).
        $response = $this->getJson("/api/v1/parent/children/{$child->id}/laundry")->assertOk();
        $response->assertJsonPath('data.quota.remaining', 0)
            ->assertJsonPath('data.wallet.balance', 80000);

        // Riwayat order + detail pembayaran dengan referensi wallet (§26).
        $this->getJson("/api/v1/parent/children/{$child->id}/laundry/orders")
            ->assertOk()
            ->assertJsonPath('data.0.order_number', $order->order_number)
            ->assertJsonPath('data.0.payment_status', 'PAID');

        $detail = $this->getJson("/api/v1/parent/children/{$child->id}/laundry/orders/{$order->id}")->assertOk();
        $this->assertSame(20000.0, (float) $detail->json('data.wallet_payment.amount'));
        $this->assertNotNull($detail->json('data.wallet_payment.wallet_transaction'));
        $this->assertSame(100000.0, (float) $detail->json('data.wallet_payment.balance_before'));
        $this->assertSame(80000.0, (float) $detail->json('data.wallet_payment.balance_after'));

        // Statement periode berjalan (§53/§72).
        $statement = $this->getJson("/api/v1/parent/children/{$child->id}/laundry/statement")->assertOk();
        $this->assertSame(10.0, (float) $statement->json('data.entitlement.allocated'));
        $this->assertSame(20000.0, (float) $statement->json('data.totals.paid_amount'));

        // Anak orang lain → 403 (Rule 20).
        $this->getJson("/api/v1/parent/children/{$stranger->id}/laundry")->assertForbidden();
        $this->getJson("/api/v1/parent/children/{$stranger->id}/laundry/orders")->assertForbidden();
    }

    public function test_santri_melihat_datanya_sendiri_lewat_me(): void
    {
        $student = $this->makeStudent('Santri Mandiri');
        $this->makeWallet($student, 0);
        $this->publishPolicy(['quota_amount' => 10]);
        $this->pricePerKg();

        $order = $this->orders->calculate($this->weighedOrder($student, 4.0), $this->laundryAdmin);

        $studentUser = $this->makeStudentUserFor($student);
        Sanctum::actingAs($studentUser, ['*']);

        $this->getJson('/api/v1/me/laundry/quota')
            ->assertOk()
            ->assertJsonPath('data.remaining', 6)
            ->assertJsonPath('data.used', 4);

        $this->getJson('/api/v1/me/laundry/orders')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', $order->order_number);

        $this->getJson("/api/v1/me/laundry/orders/{$order->id}")->assertOk();
        $this->getJson('/api/v1/me/laundry/statement')->assertOk();

        // Santri tidak bisa menembus endpoint admin.
        $this->getJson('/api/v1/laundry/orders')->assertForbidden();
    }

    public function test_endpoint_admin_terkunci_tanpa_izin(): void
    {
        $outsider = $this->makeUser('Orang Luar');
        Sanctum::actingAs($outsider, ['*']);

        $this->getJson('/api/v1/laundry/dashboard')->assertForbidden();
        $this->getJson('/api/v1/laundry/orders')->assertForbidden();
        $this->getJson('/api/v1/laundry/entitlements')->assertForbidden();
        $this->postJson('/api/v1/laundry/orders', [])->assertForbidden();
    }

    public function test_admin_melihat_dashboard_dan_identifikasi_rfid(): void
    {
        $student = $this->makeStudent('Admin View');
        $this->makeWallet($student, 30000, 'UID-ADMINVIEW');
        $this->publishPolicy(['quota_amount' => 10]);
        $this->pricePerKg();
        $order = $this->orders->calculate($this->weighedOrder($student, 3.0), $this->laundryAdmin);

        $this->actingAsLaundryAdmin();

        $this->getJson('/api/v1/laundry/dashboard')->assertOk()
            ->assertJsonPath('data.today.orders', 1);

        // §17: tap RFID memuat profil + jatah + saldo + order terbuka.
        $identify = $this->postJson('/api/v1/laundry/identify', ['uid' => 'UID-ADMINVIEW'])->assertOk();
        $this->assertSame($student->id, (int) $identify->json('data.student.id'));
        $this->assertSame(7.0, (float) $identify->json('data.quota.remaining'));
        $this->assertSame(30000.0, (float) $identify->json('data.wallet.balance'));
        $this->assertCount(1, $identify->json('data.open_orders'));

        // Pratinjau pembayaran (§54).
        $preview = $this->getJson("/api/v1/laundry/orders/{$order->id}/payment-preview")->assertOk();
        $this->assertSame(0.0, (float) $preview->json('data.payable'));
    }

    public function test_order_tanpa_santri_aktif_ditolak(): void
    {
        $inactive = $this->makeStudent('Santri Nonaktif', active: false);
        $this->publishPolicy(['quota_amount' => 10]);

        try {
            $this->orders->create([
                'student_id' => $inactive->id,
                'laundry_service_id' => $this->regularService()->id,
            ], $this->laundryAdmin);
            $this->fail('Santri nonaktif seharusnya ditolak (Rule 2).');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('student_id', $exception->errors());
        }
    }

    public function test_harga_snapshot_tidak_berubah_saat_rule_baru_naik(): void
    {
        $student = $this->makeStudent('Snapshot Harga');
        $this->makeWallet($student, 200000);
        $this->publishPolicy(['quota_amount' => 0]);
        $this->pricePerKg(null, 10000);

        $order = $this->orders->calculate($this->weighedOrder($student, 5.0), $this->laundryAdmin);
        $this->assertSame(50000.0, (float) $order->total_amount);

        // Harga naik bulan depan; order lama tetap memakai snapshot (Rule 23).
        app(LaundryCatalogService::class)->createPricingRule([
            'laundry_service_id' => $this->regularService()->id,
            'pricing_model' => 'WEIGHT',
            'unit_price' => 15000,
            'effective_from' => now()->subYear()->toDateString(),
            'priority' => 10,
        ], $this->laundryAdmin);

        $this->assertSame(50000.0, (float) $order->fresh()->total_amount);
        $snapshot = $order->fresh()->pricing_snapshot;
        $this->assertSame(50000.0, (float) $snapshot['paid_amount']);
    }
}
