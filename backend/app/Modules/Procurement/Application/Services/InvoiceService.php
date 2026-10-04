<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Contracts\AccountingProviderInterface;
use App\Modules\Procurement\Domain\Enums\ExceptionStatus;
use App\Modules\Procurement\Domain\Enums\ExceptionType;
use App\Modules\Procurement\Domain\Enums\InvoiceStatus;
use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\InvoiceException;
use App\Modules\Procurement\Models\VendorInvoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Penerimaan invoice vendor (§28, §31, §34).
 *
 * Deteksi invoice ganda memakai kombinasi vendor + nomor invoice + tanggal +
 * nominal; invoice ganda tidak boleh menjadi payable tanpa override beralasan.
 */
class InvoiceService
{
    public function __construct(
        private readonly ProcurementNumberingService $numbering,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementEventService $events,
        private readonly AccountingProviderInterface $accounting,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return VendorInvoice::query()
            ->with(['vendor:id,code,legal_name', 'purchaseOrder:id,number', 'items', 'exceptions'])
            ->when($filters['vendor_id'] ?? null, fn ($q, $id) => $q->where('vendor_id', (int) $id))
            ->when($filters['purchase_order_id'] ?? null, fn ($q, $id) => $q->where('purchase_order_id', (int) $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['match_status'] ?? null, fn ($q, $s) => $q->where('match_status', $s))
            ->when($filters['search'] ?? null, fn ($q, $t) => $q
                ->where('number', 'like', "%{$t}%")
                ->orWhere('vendor_invoice_number', 'like', "%{$t}%"))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function intake(array $data): VendorInvoice
    {
        return DB::transaction(function () use ($data): VendorInvoice {
            $items = (array) ($data['items'] ?? []);
            unset($data['items']);

            $data['number'] ??= $this->numbering->next(ProcurementNumberingService::CODE_INVOICE);
            $data['status'] ??= InvoiceStatus::RECEIVED->value;
            $data['created_by'] ??= \Illuminate\Support\Facades\Auth::id();

            $invoice = VendorInvoice::query()->create($data);

            foreach ($items as $row) {
                $quantity = (float) ($row['quantity'] ?? 0);
                $unitPrice = (float) ($row['unit_price'] ?? 0);
                $discount = (float) ($row['discount_amount'] ?? 0);
                $tax = (float) ($row['tax_amount'] ?? 0);
                $subtotal = round($quantity * $unitPrice, 2);

                $invoice->items()->create([
                    'purchase_order_item_id' => $row['purchase_order_item_id'] ?? null,
                    'goods_receipt_item_id' => $row['goods_receipt_item_id'] ?? null,
                    'description' => $row['description'] ?? '-',
                    'quantity' => $quantity,
                    'unit' => $row['unit'] ?? 'unit',
                    'unit_price' => $unitPrice,
                    'discount_amount' => $discount,
                    'tax_amount' => $tax,
                    'subtotal' => $subtotal,
                    'total' => round($subtotal - $discount + $tax, 2),
                ]);
            }

            $this->recalculate($invoice);
            $this->detectDuplicate($invoice);

            $this->audit->record(ProcurementAuditAction::CREATE, $invoice, null, $invoice->toArray());
            $this->events->dispatch('InvoiceReceived', $invoice, ['number' => $invoice->number, 'total' => (float) $invoice->total]);

            return $invoice->fresh(['items', 'exceptions']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(VendorInvoice $invoice, array $data): VendorInvoice
    {
        if (in_array($invoice->status, [InvoiceStatus::POSTED, InvoiceStatus::CANCELLED], true)) {
            throw ProcurementException::because('Invoice yang sudah terposting/dibatalkan tidak dapat diubah.', 'INVOICE_LOCKED');
        }

        return DB::transaction(function () use ($invoice, $data): VendorInvoice {
            $old = $invoice->toArray();
            $invoice->fill($data)->save();
            $this->recalculate($invoice);

            $this->audit->record(ProcurementAuditAction::UPDATE, $invoice, $old, $invoice->fresh()->toArray());

            return $invoice->fresh(['items']);
        });
    }

    /** Deteksi invoice ganda (§31). @return InvoiceException|null */
    public function detectDuplicate(VendorInvoice $invoice): ?InvoiceException
    {
        $duplicate = VendorInvoice::query()
            ->where('id', '!=', $invoice->id)
            ->where('vendor_id', $invoice->vendor_id)
            ->where(function ($q) use ($invoice): void {
                $q->where('vendor_invoice_number', $invoice->vendor_invoice_number)
                    ->orWhere(fn ($inner) => $inner
                        ->whereDate('invoice_date', $invoice->invoice_date)
                        ->where('total', $invoice->total));
            })
            ->first();

        if ($duplicate === null) {
            return null;
        }

        $invoice->forceFill([
            'duplicate_of_id' => $duplicate->id,
            'status' => InvoiceStatus::EXCEPTION->value,
        ])->save();

        $exception = InvoiceException::query()->create([
            'vendor_invoice_id' => $invoice->id,
            'type' => ExceptionType::DUPLICATE_INVOICE->value,
            'status' => ExceptionStatus::DETECTED->value,
            'severity' => 'HIGH',
            'description' => "Kemungkinan duplikat dari invoice {$duplicate->number}.",
            'detected_at' => now(),
        ]);

        $this->events->dispatch('InvoiceDuplicateDetected', $invoice, ['duplicate_of' => $duplicate->id]);

        return $exception;
    }

    /** Setujui invoice (setelah matching/exception selesai) (§32). */
    public function approve(VendorInvoice $invoice, ?int $userId = null): VendorInvoice
    {
        if ($invoice->exceptions()->open()->exists()) {
            throw ProcurementException::because('Invoice masih memiliki pengecualian yang belum selesai.', 'INVOICE_HAS_OPEN_EXCEPTIONS');
        }

        $invoice->forceFill([
            'status' => InvoiceStatus::APPROVED->value,
            'approved_by' => $userId ?? \Illuminate\Support\Facades\Auth::id(),
            'approved_at' => now(),
        ])->save();

        $this->audit->record(ProcurementAuditAction::APPROVE, $invoice, null, ['status' => 'APPROVED']);
        $this->events->dispatch('InvoiceApproved', $invoice, ['number' => $invoice->number]);

        return $invoice->fresh();
    }

    /** Status pembayaran dibaca dari Accounting — bukan disimpan (§34). */
    public function paymentStatus(VendorInvoice $invoice): array
    {
        $reference = $this->integrationReference($invoice);
        $status = $reference !== null ? $this->accounting->getPaymentStatus($reference) : null;

        return [
            'invoice_number' => $invoice->number,
            'total' => (float) $invoice->total,
            'paid_amount' => (float) $invoice->paid_amount,
            'status' => $status['status'] ?? $this->fallbackPaymentStatus($invoice),
            'source' => $status !== null ? 'ACCOUNTING' : 'LOCAL_MIRROR',
        ];
    }

    protected function integrationReference(VendorInvoice $invoice): ?string
    {
        return $invoice->integrations()
            ->whereNotNull('external_id')
            ->latest('id')
            ->value('external_id');
    }

    protected function fallbackPaymentStatus(VendorInvoice $invoice): string
    {
        if ($invoice->isOverdue()) {
            return 'OVERDUE';
        }

        $paid = (float) $invoice->paid_amount;
        $total = (float) $invoice->total;

        return match (true) {
            $paid <= 0 => 'UNPAID',
            $paid < $total => 'PARTIALLY_PAID',
            default => 'PAID',
        };
    }

    protected function recalculate(VendorInvoice $invoice): void
    {
        $invoice->loadMissing('items');

        $subtotal = round((float) $invoice->items->sum('subtotal'), 2);
        $itemsTotal = round((float) $invoice->items->sum('total'), 2);

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'total' => round($itemsTotal - (float) $invoice->discount_amount + (float) $invoice->tax_amount + (float) $invoice->shipping_amount, 2),
        ])->save();
    }
}
