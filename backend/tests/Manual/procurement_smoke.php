<?php

/**
 * Uji asap (smoke test) Modul Procurement & Vendor — alur emas end-to-end.
 *
 * Menjalankan: vendor → katalog → PR → approval → RFQ → penawaran → perbandingan →
 * evaluasi → pemilihan vendor → PO → approval → terbit → konfirmasi → penerimaan
 * (sebagian lalu penuh) → inspeksi → invoice → 3-way matching → handoff Accounting
 * (idempoten) → status pembayaran.
 *
 * Jalankan:  php tests/Manual/procurement_smoke.php
 */

use App\Modules\Procurement\Application\Services\AccountingHandoffService;
use App\Modules\Procurement\Application\Services\InvoiceMatchingService;
use App\Modules\Procurement\Application\Services\InvoiceService;
use App\Modules\Procurement\Application\Services\ProcurementNumberingService;
use App\Modules\Procurement\Application\Services\PurchaseOrderService;
use App\Modules\Procurement\Application\Services\PurchaseRequestService;
use App\Modules\Procurement\Application\Services\QuotationEvaluationService;
use App\Modules\Procurement\Application\Services\QuotationService;
use App\Modules\Procurement\Application\Services\ReceivingService;
use App\Modules\Procurement\Application\Services\RfqService;
use App\Modules\Procurement\Application\Services\VendorSelectionService;
use App\Modules\Procurement\Application\Services\VendorService;
use App\Modules\Procurement\Models\Category;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$pass = 0;
$fail = 0;

/** @param mixed $actual */
function check(string $label, $actual, $expected): void
{
    global $pass, $fail;

    if ($actual === $expected) {
        $pass++;
        echo "  [OK]   {$label}\n";

        return;
    }

    $fail++;
    echo "  [GAGAL] {$label} — diharapkan ".var_export($expected, true).', dapat '.var_export($actual, true)."\n";
}

echo "== Modul Procurement — uji asap alur emas ==\n";

$suffix = now()->format('YmdHis');

/** @var VendorService $vendors */
$vendors = app(VendorService::class);
$requests = app(PurchaseRequestService::class);
$rfqs = app(RfqService::class);
$quotations = app(QuotationService::class);
$evaluations = app(QuotationEvaluationService::class);
$selections = app(VendorSelectionService::class);
$orders = app(PurchaseOrderService::class);
$receiving = app(ReceivingService::class);
$invoices = app(InvoiceService::class);
$matching = app(InvoiceMatchingService::class);
$handoff = app(AccountingHandoffService::class);
$numbering = app(ProcurementNumberingService::class);

// PR wajib punya pemohon (kolom NOT NULL) — pakai user pertama yang ada.
$requesterId = (int) (Illuminate\Support\Facades\DB::table('users')->value('id') ?? 0);
if ($requesterId === 0) {
    echo "Tidak ada user di basis data — jalankan `php artisan migrate:fresh --seed` dulu.\n";
    exit(2);
}

// ---------- 1. Vendor: daftar → verifikasi → aktif ----------
echo "\n[1] Vendor & onboarding\n";

/** Daftarkan vendor lalu jalankan onboarding sampai aktif. */
$makeVendor = function (string $tag) use ($vendors, $suffix) {
    $v = $vendors->create([
        'legal_name' => 'PT Uji Asap '.$tag.' '.$suffix,
        'vendor_type' => 'SUPPLIER',
        'email' => 'smoke-'.$tag.'-'.$suffix.'@example.test',
        'currency' => 'IDR',
        'payment_terms' => 'NET 30',
    ]);
    $vendors->submit($v);
    $vendors->verify($v, null);

    return $vendors->approve($v);
};

$vendor = $makeVendor('A');
check('kode vendor terisi', $vendor->code !== null && $vendor->code !== '', true);
check('vendor aktif', $vendor->status->value ?? $vendor->status, 'ACTIVE');
check('vendor layak dipakai', $vendor->isUsable(), true);

$vendorB = $makeVendor('B');
$vendorC = $makeVendor('C');
check('vendor B aktif', $vendorB->status->value ?? $vendorB->status, 'ACTIVE');
check('vendor C aktif', $vendorC->status->value ?? $vendorC->status, 'ACTIVE');

// ---------- 2. Katalog ----------
echo "\n[2] Katalog & kategori\n";
$category = Category::query()->firstOrCreate(
    ['code' => 'SMOKE-'.$suffix],
    ['name' => 'Kategori Uji '.$suffix, 'item_type' => 'GOODS', 'is_active' => true],
);
check('kategori tersedia', $category->exists, true);

// ---------- 3. PR ----------
echo "\n[3] Purchase Request\n";
$pr = $requests->create([
    'requester_id' => $requesterId,
    'required_date' => now()->addDays(14)->toDateString(),
    'priority' => 'NORMAL',
    'purpose' => 'Uji asap modul pengadaan',
    'currency' => 'IDR',
    'items' => [[
        'description' => 'Laptop uji',
        'item_type' => 'GOODS',
        'quantity' => 10,
        'unit' => 'unit',
        'estimated_unit_price' => 8_000_000,
        'is_asset' => true,
        'category_id' => $category->id,
    ]],
]);
check('nomor PR terbentuk', str_starts_with((string) $pr->number, 'PR-'), true);
check('estimasi total PR', (float) $pr->estimated_total, 80_000_000.0);

$validation = $requests->validate($pr);
check('validasi PR mengembalikan daftar temuan', is_array($validation['issues']), true);

$pr = $requests->submit($pr);
check('PR diajukan', $pr->status->value ?? $pr->status, 'SUBMITTED');
check('langkah approval terbentuk', $pr->approvals()->count() > 0, true);

// Setujui seluruh langkah.
while ($pr->approvals()->where('status', 'PENDING')->exists()) {
    $pr = $requests->approve($pr, null, 'Disetujui uji asap');
}
check('PR disetujui', $pr->status->value ?? $pr->status, 'APPROVED');

// ---------- 4. Konsolidasi → RFQ ----------
echo "\n[4] RFQ & penawaran\n";
$event = $requests->consolidate([$pr->id], 'Peristiwa uji '.$suffix, null);
check('peristiwa pengadaan dibuat', $event->exists, true);

$rfq = $rfqs->create([
    'title' => 'RFQ uji '.$suffix,
    'event_id' => $event->id,
    'currency' => 'IDR',
    'vendors' => [$vendor->id, $vendorB->id, $vendorC->id],
    'items' => [[
        'purchase_request_item_id' => $pr->items()->first()->id,
        'description' => 'Laptop uji',
        'quantity' => 10,
        'unit' => 'unit',
    ]],
]);
$rfq = $rfqs->issue($rfq);
check('RFQ terbit', $rfq->status->value ?? $rfq->status, 'ISSUED');

$rfqItem = $rfq->items()->first();

/** Catat penawaran vendor untuk item RFQ. */
$quote = function (int $vendorId, float $unitPrice) use ($quotations, $rfq, $rfqItem, $event) {
    return $quotations->record([
        'rfq_id' => $rfq->id,
        'vendor_id' => $vendorId,
        'event_id' => $event->id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency' => 'IDR',
        'payment_terms' => 'NET 30',
        'delivery_days' => 14,
        'items' => [[
            'rfq_item_id' => $rfqItem->id,
            'description' => 'Laptop uji',
            'quantity' => 10,
            'unit' => 'unit',
            'unit_price' => $unitPrice,
        ]],
    ]);
};

$quotation = $quote($vendor->id, 7_900_000);   // terendah → calon pemenang
$quote($vendorB->id, 8_050_000);
$quote($vendorC->id, 8_100_000);
check('total penawaran', (float) $quotation->total, 79_000_000.0);

$comparison = $quotations->comparison($rfq->id);
check('matriks perbandingan berisi 3 vendor', count($comparison['vendors']), 3);

// ---------- 5. Evaluasi & pemilihan ----------
echo "\n[5] Evaluasi & pemilihan vendor\n";
$evaluation = $evaluations->evaluate($quotation, [
    'evaluator_id' => null,
    'recommendation' => 'Harga terbaik',
    'scores' => [
        ['code' => 'PRICE', 'score' => 95],
        ['code' => 'TECHNICAL', 'score' => 90],
    ],
]);
check('skor tertimbang > 0', (float) $evaluation->weighted_score > 0, true);

$selection = $selections->select($rfq->id, [
    'quotation_id' => $quotation->id,
    'rationale' => 'Harga terendah dengan spesifikasi terpenuhi.',
    'recommendation' => 'Pilih vendor ini',
]);
check('vendor terpilih', $selection->selected_vendor_id, $vendor->id);
$selection = $selections->approve($selection, null);
check('pemilihan disetujui', $selection->status, 'APPROVED');

// ---------- 6. PO ----------
echo "\n[6] Purchase Order\n";
$order = $orders->createFromSelection($selection->id, [
    'expected_delivery_date' => now()->addDays(21)->toDateString(),
    'requester_id' => $requesterId,
    'created_by' => null,
]);
check('nomor PO terbentuk', str_starts_with((string) $order->number, 'PO-'), true);
check('grand total PO', (float) $order->grand_total, 79_000_000.0);
check('versi awal PO tercatat', $order->versions()->count(), 1);

$order = $orders->submitForApproval($order);
check('PO menunggu persetujuan', $order->status->value ?? $order->status, 'PENDING_APPROVAL');

while ($order->approvals()->where('status', 'PENDING')->exists()) {
    $order = $orders->approve($order, null, 'Disetujui');
}
check('PO disetujui', $order->status->value ?? $order->status, 'APPROVED');

$order = $orders->issue($order);
check('PO terbit', $order->status->value ?? $order->status, 'ISSUED');

$orders->confirm($order, ['status' => 'ACCEPTED'], null);
$order = $order->fresh();
check('PO dikonfirmasi vendor', $order->status->value ?? $order->status, 'VENDOR_CONFIRMED');

// Amandemen non-material → versi baru, histori utuh.
$orders->amend($order, ['expected_delivery_date' => now()->addDays(28)->toDateString()], 'Penyesuaian jadwal', false, null);
$order = $order->fresh();
check('versi PO bertambah', $order->versions()->count(), 2);
check('harga PO tidak berubah oleh amandemen', (float) $order->grand_total, 79_000_000.0);

// ---------- 7. Penerimaan ----------
echo "\n[7] Penerimaan barang (sebagian lalu penuh)\n";
$poItem = $order->items()->first();

$receipt1 = $receiving->receive($order, [
    'receipt_date' => now()->toDateString(),
    'items' => [[
        'purchase_order_item_id' => $poItem->id,
        'delivered_quantity' => 6,
        'accepted_quantity' => 6,
    ]],
]);
check('penerimaan pertama dicatat', str_starts_with((string) $receipt1->number, 'GR-'), true);
check('PO diterima sebagian', $order->fresh()->status->value ?? $order->fresh()->status, 'PARTIALLY_RECEIVED');

$receipt2 = $receiving->receive($order->fresh(), [
    'receipt_date' => now()->toDateString(),
    'items' => [[
        'purchase_order_item_id' => $poItem->id,
        'delivered_quantity' => 4,
        'accepted_quantity' => 4,
    ]],
]);
check('penerimaan kedua dicatat', $receipt2->exists, true);
check('PO diterima penuh', $order->fresh()->status->value ?? $order->fresh()->status, 'FULLY_RECEIVED');
check('kuantitas diterima = 10', (float) $order->fresh()->items()->first()->received_quantity, 10.0);

// ---------- 8. Invoice & matching ----------
echo "\n[8] Invoice & 3-way matching\n";
$invoice = $invoices->intake([
    'vendor_id' => $vendor->id,
    'vendor_invoice_number' => 'INV-UJI-'.$suffix,
    'invoice_date' => now()->toDateString(),
    'due_date' => now()->addDays(30)->toDateString(),
    'purchase_order_id' => $order->id,
    'currency' => 'IDR',
    'submitted_by' => null,
    'items' => [[
        'purchase_order_item_id' => $poItem->id,
        'description' => 'Laptop uji',
        'quantity' => 10,
        'unit' => 'unit',
        'unit_price' => 7_900_000,
    ]],
]);
check('nomor invoice terbentuk', str_starts_with((string) $invoice->number, 'INV-'), true);
check('total invoice', (float) $invoice->total, 79_000_000.0);

$matchResult = $matching->match($invoice, null);
check('matching cocok', $matchResult->match_status->value ?? $matchResult->match_status, 'MATCHED');
check('invoice cocok', $invoice->fresh()->status->value ?? $invoice->fresh()->status, 'MATCHED');

$invoice = $invoices->approve($invoice->fresh(), null);
check('invoice disetujui', $invoice->status->value ?? $invoice->status, 'APPROVED');

// ---------- 9. Handoff Accounting (idempoten) ----------
echo "\n[9] Handoff Accounting & idempotensi\n";
$first = $handoff->handoff($invoice);
$second = $handoff->retry($invoice);

check('hanya satu catatan integrasi', $invoice->integrations()->count(), 1);
check('kunci idempotensi tetap sama', $first->idempotency_key, $second->idempotency_key);

$status = $invoices->paymentStatus($invoice);
check('status pembayaran tersedia', isset($status['status']), true);

// ---------- Ringkasan ----------
echo "\n========================================\n";
echo "LULUS: {$pass}   GAGAL: {$fail}\n";
echo "========================================\n";

exit($fail === 0 ? 0 : 1);
