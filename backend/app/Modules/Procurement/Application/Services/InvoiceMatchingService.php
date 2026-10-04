<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\ExceptionStatus;
use App\Modules\Procurement\Domain\Enums\ExceptionType;
use App\Modules\Procurement\Domain\Enums\InvoiceStatus;
use App\Modules\Procurement\Domain\Enums\MatchStatus;
use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\GoodsReceiptItem;
use App\Modules\Procurement\Models\InvoiceException;
use App\Modules\Procurement\Models\InvoiceMatchResult;
use App\Modules\Procurement\Models\VendorInvoice;
use Illuminate\Support\Facades\DB;

/**
 * 3-way matching PO + Penerimaan + Invoice (§29, §30).
 *
 * Mismatch tidak langsung dikirim ke Accounting: ia menjadi **pengecualian**
 * yang harus diselesaikan lebih dahulu (§30, §52.8).
 */
class InvoiceMatchingService
{
    public function __construct(
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementEventService $events,
        private readonly ProcurementControlService $control,
        private readonly IntegrationService $integrations,
    ) {
    }

    public function match(VendorInvoice $invoice, ?int $userId = null): InvoiceMatchResult
    {
        return DB::transaction(function () use ($invoice, $userId): InvoiceMatchResult {
            $invoice->loadMissing('items.purchaseOrderItem', 'purchaseOrder');
            $tolerance = $this->control->matchingTolerance();

            $qtyTolerance = (float) ($tolerance['quantity_tolerance_percent'] ?? 0);
            $priceTolerance = (float) ($tolerance['price_tolerance_percent'] ?? 0);
            $amountTolerance = (float) ($tolerance['amount_tolerance'] ?? 0);

            $maxQtyVariance = 0.0;
            $maxPriceVariance = 0.0;
            $mismatches = [];
            $poExpectedTotal = 0.0;

            foreach ($invoice->items as $item) {
                $poItem = $item->purchaseOrderItem;

                if ($poItem === null) {
                    $mismatches[] = ['type' => ExceptionType::WRONG_PO, 'message' => "Baris \"{$item->description}\" tidak tertaut ke PO."];
                    continue;
                }

                $received = (float) GoodsReceiptItem::query()
                    ->where('purchase_order_item_id', $poItem->id)
                    ->sum('accepted_quantity');

                $invQty = (float) $item->quantity;
                $poPrice = (float) $poItem->unit_price;
                $invPrice = (float) $item->unit_price;

                $qtyVariance = round($invQty - $received, 4);
                $priceVariance = round($invPrice - $poPrice, 2);

                $poExpectedTotal += round($received * $poPrice, 2);

                $maxQtyVariance = max($maxQtyVariance, abs($qtyVariance));
                $maxPriceVariance = max($maxPriceVariance, abs($priceVariance));

                if ($received <= 0) {
                    $mismatches[] = ['type' => ExceptionType::MISSING_RECEIVING, 'message' => "Belum ada penerimaan untuk \"{$item->description}\"."];
                }

                if (abs($qtyVariance) > 0.0001 && $received > 0 && ($qtyTolerance <= 0 || abs($qtyVariance) / max($received, 1) * 100 > $qtyTolerance)) {
                    $mismatches[] = ['type' => ExceptionType::QUANTITY_MISMATCH, 'message' => "Jumlah tidak sesuai untuk \"{$item->description}\" (PO/terima {$received}, tagih {$invQty})."];
                }

                if ($poPrice > 0 && abs($priceVariance) / $poPrice * 100 > $priceTolerance) {
                    $mismatches[] = ['type' => ExceptionType::PRICE_MISMATCH, 'message' => "Harga tidak sesuai untuk \"{$item->description}\" (PO {$poPrice}, tagih {$invPrice})."];
                }
            }

            $amountVariance = round((float) $invoice->total - $poExpectedTotal, 2);

            if (abs($amountVariance) > $amountTolerance && $amountVariance > 0) {
                $mismatches[] = ['type' => ExceptionType::OVERBILLING, 'message' => "Total tagihan melebihi nilai penerimaan sebesar {$amountVariance}."];
            }

            $status = match (true) {
                $mismatches === [] => MatchStatus::MATCHED,
                count($mismatches) <= 1 => MatchStatus::PARTIALLY_MATCHED,
                default => MatchStatus::MISMATCH,
            };

            $result = InvoiceMatchResult::query()->create([
                'vendor_invoice_id' => $invoice->id,
                'purchase_order_id' => $invoice->purchase_order_id,
                'match_status' => $status->value,
                'quantity_variance' => $maxQtyVariance,
                'price_variance' => $maxPriceVariance,
                'amount_variance' => $amountVariance,
                'tolerance_snapshot' => $tolerance,
                'details' => ['mismatches' => $mismatches, 'po_expected_total' => $poExpectedTotal],
                'matched_at' => now(),
                'matched_by' => $userId ?? \Illuminate\Support\Facades\Auth::id(),
            ]);

            foreach ($mismatches as $mismatch) {
                InvoiceException::query()->create([
                    'vendor_invoice_id' => $invoice->id,
                    'type' => ($mismatch['type'] ?? ExceptionType::INVALID_INVOICE)->value,
                    'status' => ExceptionStatus::DETECTED->value,
                    'severity' => $status === MatchStatus::MATCHED ? 'LOW' : 'MEDIUM',
                    'description' => $mismatch['message'] ?? null,
                    'detected_at' => now(),
                ]);
            }

            $invoice->forceFill([
                'match_status' => $status->value,
                'status' => $status === MatchStatus::MATCHED ? InvoiceStatus::MATCHED->value : InvoiceStatus::EXCEPTION->value,
            ])->save();

            $this->audit->record(ProcurementAuditAction::MATCH, $invoice, null, ['match_status' => $status->value]);
            $this->events->dispatch(
                $status === MatchStatus::MATCHED ? 'InvoiceMatched' : 'InvoiceMismatchDetected',
                $invoice,
                ['match_status' => $status->value, 'mismatches' => count($mismatches)],
            );

            return $result;
        });
    }

    /** Pindahkan pengecualian ke tahap investigasi. */
    public function startInvestigation(InvoiceException $exception, ?int $userId, ?string $notes = null): InvoiceException
    {
        $exception->forceFill([
            'status' => ExceptionStatus::INVESTIGATION->value,
            'assigned_to' => $userId,
            'investigation_notes' => $notes,
        ])->save();

        $this->audit->record(ProcurementAuditAction::UPDATE, $exception, null, ['status' => 'INVESTIGATION']);

        return $exception->fresh();
    }

    /** Selesaikan pengecualian (dengan alasan). */
    public function resolve(InvoiceException $exception, ?int $userId, string $resolution, bool $approve = false): InvoiceException
    {
        $exception->forceFill([
            'status' => $approve ? ExceptionStatus::APPROVED->value : ExceptionStatus::RESOLVED->value,
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'resolution_notes' => $resolution,
            'approved_by' => $approve ? $userId : $exception->approved_by,
            'approved_at' => $approve ? now() : $exception->approved_at,
        ])->save();

        $this->audit->record(
            $approve ? ProcurementAuditAction::APPROVE : ProcurementAuditAction::UPDATE,
            $exception,
            null,
            ['status' => $exception->status instanceof \BackedEnum ? $exception->status->value : $exception->status],
            $resolution,
        );

        $this->refreshInvoiceFromExceptions($exception->vendor_invoice_id);

        return $exception->fresh();
    }

    /**
     * Override matching dengan alasan — wajib beralasan, tidak boleh senyap (§53).
     */
    public function override(VendorInvoice $invoice, string $reason, ?int $userId): VendorInvoice
    {
        if (blank($reason)) {
            throw ProcurementException::because('Override matching wajib disertai alasan.', 'OVERRIDE_REASON_REQUIRED');
        }

        DB::transaction(function () use ($invoice, $reason, $userId): void {
            $invoice->exceptions()->open()->update([
                'status' => ExceptionStatus::APPROVED->value,
                'override_reason' => $reason,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            $invoice->forceFill([
                'match_status' => MatchStatus::PARTIALLY_MATCHED->value,
                'status' => InvoiceStatus::APPROVED->value,
                'approved_by' => $userId,
                'approved_at' => now(),
            ])->save();

            $this->audit->record(ProcurementAuditAction::OVERRIDE, $invoice, null, ['match_status' => 'PARTIALLY_MATCHED'], $reason);
        });

        return $invoice->fresh();
    }

    protected function refreshInvoiceFromExceptions(int $invoiceId): void
    {
        $invoice = VendorInvoice::query()->find($invoiceId);

        if ($invoice === null) {
            return;
        }

        if (! $invoice->exceptions()->open()->exists()) {
            $invoice->forceFill(['status' => InvoiceStatus::APPROVED->value, 'approved_at' => now()])->save();
        }
    }
}
