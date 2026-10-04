<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Domain\Enums\QuotationStatus;
use App\Modules\Procurement\Domain\Enums\RfqStatus;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\Quotation;
use App\Modules\Procurement\Models\Rfq;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Penawaran vendor (§13–§14).
 *
 * Nilai disimpan sebagai **snapshot**; perbandingan (comparison matrix)
 * dihasilkan dari data tersimpan sehingga dapat diaudit.
 */
class QuotationService
{
    public function __construct(
        private readonly ProcurementNumberingService $numbering,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementEventService $events,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return Quotation::query()
            ->with(['vendor:id,code,legal_name', 'rfq:id,number,title', 'items'])
            ->when($filters['rfq_id'] ?? null, fn ($q, $id) => $q->where('rfq_id', (int) $id))
            ->when($filters['vendor_id'] ?? null, fn ($q, $id) => $q->where('vendor_id', (int) $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** Catat penawaran baru beserta itemnya (§13). @param array<string, mixed> $data */
    public function record(array $data): Quotation
    {
        return DB::transaction(function () use ($data): Quotation {
            $items = (array) ($data['items'] ?? []);
            unset($data['items']);

            $data['number'] ??= $this->numbering->next(ProcurementNumberingService::CODE_QUOTATION);
            $data['status'] ??= QuotationStatus::RECEIVED->value;

            $quotation = Quotation::query()->create($data);
            $this->replaceItems($quotation, $items);
            $this->recalculate($quotation);

            if ($quotation->rfq_id !== null) {
                $this->markRfqResponse($quotation->rfq_id, $quotation->vendor_id);
            }

            $this->audit->record(ProcurementAuditAction::CREATE, $quotation, null, $quotation->toArray());
            $this->events->dispatch('QuotationReceived', $quotation, ['number' => $quotation->number, 'vendor_id' => $quotation->vendor_id]);

            return $quotation->fresh(['items', 'vendor']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Quotation $quotation, array $data): Quotation
    {
        if ($quotation->status === QuotationStatus::SELECTED) {
            throw ProcurementException::because('Penawaran yang sudah dipilih tidak dapat diubah.', 'QUOTATION_LOCKED');
        }

        return DB::transaction(function () use ($quotation, $data): Quotation {
            $items = array_key_exists('items', $data) ? (array) $data['items'] : null;
            unset($data['items']);

            $quotation->fill($data)->save();

            if ($items !== null) {
                $this->replaceItems($quotation, $items);
            }

            $this->recalculate($quotation);
            $this->audit->record(ProcurementAuditAction::UPDATE, $quotation, null, $quotation->fresh()->toArray());

            return $quotation->fresh(['items']);
        });
    }

    /**
     * Matriks perbandingan penawaran (§14).
     *
     * @return array{vendors: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    public function comparison(int $rfqId): array
    {
        $rfq = Rfq::query()->with(['items', 'quotations.vendor', 'quotations.items'])->findOrFail($rfqId);

        $vendors = [];

        foreach ($rfq->quotations as $quotation) {
            $vendors[] = [
                'quotation_id' => $quotation->id,
                'vendor_id' => $quotation->vendor_id,
                'vendor' => $quotation->vendor?->legal_name,
                'price' => (float) $quotation->total,
                'delivery_days' => $quotation->delivery_days,
                'payment_terms' => $quotation->payment_terms,
                'shipping_cost' => (float) $quotation->shipping_cost,
                'discount_amount' => (float) $quotation->discount_amount,
                'tax_amount' => (float) $quotation->tax_amount,
                'valid_until' => $quotation->valid_until?->toDateString(),
                'status' => $quotation->status instanceof \BackedEnum ? $quotation->status->value : $quotation->status,
                'is_lowest_price' => false,
            ];
        }

        // Tandai harga terendah sebagai informasi — bukan keputusan otomatis (§14).
        $lowest = null;
        foreach ($vendors as $index => $row) {
            if ($lowest === null || $row['price'] < $lowest['price']) {
                $lowest = $row + ['index' => $index];
            }
        }

        if ($lowest !== null) {
            $vendors[$lowest['index']]['is_lowest_price'] = true;
        }

        $itemMatrix = [];

        foreach ($rfq->items as $rfqItem) {
            $row = ['rfq_item_id' => $rfqItem->id, 'description' => $rfqItem->description, 'quantity' => (float) $rfqItem->quantity, 'prices' => []];

            foreach ($rfq->quotations as $quotation) {
                $match = $quotation->items->firstWhere('rfq_item_id', $rfqItem->id);
                $row['prices'][] = [
                    'quotation_id' => $quotation->id,
                    'vendor' => $quotation->vendor?->legal_name,
                    'unit_price' => $match !== null ? (float) $match->unit_price : null,
                ];
            }

            $itemMatrix[] = $row;
        }

        return ['vendors' => $vendors, 'items' => $itemMatrix];
    }

    public function markStatus(Quotation $quotation, QuotationStatus $status): Quotation
    {
        $quotation->forceFill(['status' => $status->value])->save();
        $this->audit->record(ProcurementAuditAction::UPDATE, $quotation, null, ['status' => $status->value]);

        return $quotation->fresh();
    }

    /** @param array<int, array<string, mixed>> $items */
    protected function replaceItems(Quotation $quotation, array $items): void
    {
        $quotation->items()->delete();

        foreach ($items as $row) {
            $quantity = (float) ($row['quantity'] ?? 0);
            $unitPrice = (float) ($row['unit_price'] ?? 0);
            $discount = (float) ($row['discount_amount'] ?? 0);
            $tax = (float) ($row['tax_amount'] ?? 0);
            $subtotal = round($quantity * $unitPrice, 2);

            $quotation->items()->create([
                'rfq_item_id' => $row['rfq_item_id'] ?? null,
                'item_id' => $row['item_id'] ?? null,
                'description' => $row['description'] ?? '-',
                'specification' => $row['specification'] ?? null,
                'quantity' => $quantity,
                'unit' => $row['unit'] ?? 'unit',
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'delivery_estimate' => $row['delivery_estimate'] ?? null,
                'subtotal' => $subtotal,
                'total' => round($subtotal - $discount + $tax, 2),
            ]);
        }
    }

    protected function recalculate(Quotation $quotation): void
    {
        $quotation->loadMissing('items');

        $subtotal = round((float) $quotation->items->sum('subtotal'), 2);
        $itemsTotal = round((float) $quotation->items->sum('total'), 2);

        $quotation->forceFill([
            'subtotal' => $subtotal,
            'total' => round($itemsTotal - (float) $quotation->discount_amount + (float) $quotation->tax_amount + (float) $quotation->shipping_cost, 2),
        ])->save();
    }

    protected function markRfqResponse(int $rfqId, int $vendorId): void
    {
        $rfq = Rfq::query()->find($rfqId);

        if ($rfq === null) {
            return;
        }

        $rfq->vendors()->where('vendor_id', $vendorId)->update(['status' => 'RESPONDED', 'responded_at' => now()]);

        $total = $rfq->vendors()->count();
        $responded = $rfq->vendors()->where('status', 'RESPONDED')->count();

        $rfq->forceFill([
            'status' => $responded >= $total && $total > 0
                ? RfqStatus::CLOSED->value
                : RfqStatus::PARTIALLY_RESPONDED->value,
        ])->save();
    }
}
