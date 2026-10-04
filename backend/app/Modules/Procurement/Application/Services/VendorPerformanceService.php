<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\InvoiceStatus;
use App\Modules\Procurement\Domain\Enums\MatchStatus;
use App\Modules\Procurement\Domain\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptItem;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Models\VendorPerformanceRecord;
use Illuminate\Support\Carbon;

/**
 * Scorecard kinerja vendor (§7).
 *
 * Seluruh KPI dihitung dari data faktual (PO, penerimaan, inspeksi, invoice) —
 * bukan masukan manual — sehingga dapat diaudit dan dibandingkan antar periode.
 * Skor **bukan** satu-satunya dasar pemilihan; keputusan tetap disimpan di
 * `proc_vendor_selections`.
 */
class VendorPerformanceService
{
    /** Hitung (atau hitung ulang) scorecard vendor untuk satu periode YYYY-MM. */
    public function compute(Vendor $vendor, string $period): VendorPerformanceRecord
    {
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $orders = PurchaseOrder::query()
            ->where('vendor_id', $vendor->id)
            ->whereBetween('po_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('status', [PurchaseOrderStatus::DRAFT->value, PurchaseOrderStatus::CANCELLED->value, PurchaseOrderStatus::REJECTED->value])
            ->get();

        $poCount = $orders->count();

        $receiptItems = GoodsReceiptItem::query()
            ->whereHas('goodsReceipt', fn ($q) => $q
                ->where('vendor_id', $vendor->id)
                ->whereBetween('receipt_date', [$start->toDateString(), $end->toDateString()]))
            ->get();

        $ordered = (float) $receiptItems->sum('ordered_quantity');
        $delivered = (float) $receiptItems->sum('delivered_quantity');
        $accepted = (float) $receiptItems->sum('accepted_quantity');
        $rejected = (float) $receiptItems->sum('rejected_quantity');

        // Ketepatan waktu: bandingkan tanggal terima dengan tanggal janji PO.
        $onTime = 0;
        $late = 0;
        $delayTotal = 0.0;

        foreach ($orders as $order) {
            $receipt = $order->goodsReceipts()->orderBy('receipt_date')->first();

            if ($receipt === null || $order->expected_delivery_date === null) {
                continue;
            }

            $expected = Carbon::parse($order->expected_delivery_date);
            $actual = Carbon::parse($receipt->receipt_date);

            if ($actual->lessThanOrEqualTo($expected)) {
                $onTime++;
            } else {
                $late++;
                $delayTotal += $expected->diffInDays($actual);
            }
        }

        $invoiceCount = \App\Modules\Procurement\Models\VendorInvoice::query()
            ->where('vendor_id', $vendor->id)
            ->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('status', [InvoiceStatus::DRAFT->value, InvoiceStatus::CANCELLED->value])
            ->count();

        $accurateInvoices = \App\Modules\Procurement\Models\VendorInvoice::query()
            ->where('vendor_id', $vendor->id)
            ->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()])
            ->where('match_status', MatchStatus::MATCHED->value)
            ->count();

        $exceptions = \App\Modules\Procurement\Models\InvoiceException::query()
            ->whereHas('invoice', fn ($q) => $q->where('vendor_id', $vendor->id))
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $onTimeRate = $poCount > 0 ? round($onTime / max(1, $onTime + $late) * 100, 2) : 0.0;
        $qualityRate = $delivered > 0 ? round($accepted / $delivered * 100, 2) : 0.0;
        $fulfillmentRate = $ordered > 0 ? round($delivered / $ordered * 100, 2) : 0.0;
        $invoiceAccuracy = $invoiceCount > 0 ? round($accurateInvoices / $invoiceCount * 100, 2) : 0.0;
        $avgDelay = $late > 0 ? round($delayTotal / $late, 2) : 0.0;

        $weights = (array) config('procurement.performance_weights', []);
        $score = round(
            $onTimeRate * (float) ($weights['on_time_delivery'] ?? 0)
            + $qualityRate * (float) ($weights['quality_acceptance'] ?? 0)
            + $fulfillmentRate * (float) ($weights['order_fulfillment'] ?? 0)
            + $invoiceAccuracy * (float) ($weights['invoice_accuracy'] ?? 0)
            + max(0, 100 - $avgDelay * 10) * (float) ($weights['responsiveness'] ?? 0),
            2,
        );

        return VendorPerformanceRecord::query()->updateOrCreate(
            ['vendor_id' => $vendor->id, 'period' => $period],
            [
                'po_count' => $poCount,
                'on_time_count' => $onTime,
                'late_count' => $late,
                'total_ordered_qty' => $ordered,
                'total_received_qty' => $delivered,
                'rejected_qty' => $rejected,
                'return_count' => $rejected > 0 ? 1 : 0,
                'complaint_count' => $exceptions,
                'invoice_count' => $invoiceCount,
                'accurate_invoice_count' => $accurateInvoices,
                'avg_delay_days' => $avgDelay,
                'response_time_hours' => 0,
                'on_time_rate' => $onTimeRate,
                'quality_rate' => $qualityRate,
                'fulfillment_rate' => $fulfillmentRate,
                'invoice_accuracy_rate' => $invoiceAccuracy,
                'score' => $score,
                'grade' => $this->grade($score),
                'computed_at' => now(),
            ],
        );
    }

    /** Hitung ulang scorecard seluruh vendor aktif untuk satu periode. */
    public function computeAll(string $period): int
    {
        $count = 0;

        Vendor::query()->chunkById(100, function ($vendors) use ($period, &$count): void {
            foreach ($vendors as $vendor) {
                $this->compute($vendor, $period);
                $count++;
            }
        });

        return $count;
    }

    public function grade(float $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            default => 'D',
        };
    }
}
