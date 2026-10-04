<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Domain\Enums\ServiceReceiptStatus;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\ServiceReceipt;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Penerimaan jasa (§27). */
class ServiceReceiptService
{
    public function __construct(
        private readonly ProcurementNumberingService $numbering,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementEventService $events,
        private readonly PurchaseOrderService $orders,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return ServiceReceipt::query()
            ->with(['vendor:id,legal_name', 'purchaseOrder:id,number', 'items'])
            ->when($filters['purchase_order_id'] ?? null, fn ($q, $id) => $q->where('purchase_order_id', (int) $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(PurchaseOrder $order, array $data): ServiceReceipt
    {
        return DB::transaction(function () use ($order, $data): ServiceReceipt {
            $items = (array) ($data['items'] ?? []);
            unset($data['items']);

            $receipt = ServiceReceipt::query()->create($data + [
                'number' => $this->numbering->next(ProcurementNumberingService::CODE_SERVICE_RECEIPT),
                'purchase_order_id' => $order->id,
                'vendor_id' => $order->vendor_id,
                'status' => ServiceReceiptStatus::DRAFT->value,
            ]);

            foreach ($items as $row) {
                $receipt->items()->create($row);
            }

            $this->audit->record(ProcurementAuditAction::RECEIVE, $receipt, null, $receipt->toArray());

            return $receipt->fresh(['items']);
        });
    }

    /** Terima jasa & perbarui kuantitas diterima pada PO. */
    public function accept(ServiceReceipt $receipt, ?int $userId = null): ServiceReceipt
    {
        return DB::transaction(function () use ($receipt, $userId): ServiceReceipt {
            $receipt->loadMissing('items');

            if ($receipt->items->isEmpty()) {
                throw ProcurementException::because('Penerimaan jasa tanpa item.', 'SERVICE_RECEIPT_EMPTY');
            }

            $receivedByItem = [];

            foreach ($receipt->items as $item) {
                $item->forceFill([
                    'status' => 'ACCEPTED',
                    'accepted_quantity' => (float) $item->quantity,
                ])->save();

                if ($item->purchase_order_item_id !== null) {
                    $receivedByItem[$item->purchase_order_item_id] = (float) $item->quantity;
                }
            }

            $receipt->forceFill([
                'status' => ServiceReceiptStatus::ACCEPTED->value,
                'accepted_by' => $userId ?? \Illuminate\Support\Facades\Auth::id(),
                'accepted_at' => now(),
                'completion_percentage' => 100,
            ])->save();

            if ($receivedByItem !== []) {
                $this->orders->applyReceipt($receipt->purchaseOrder, $receivedByItem);
            }

            $this->audit->record(ProcurementAuditAction::APPROVE, $receipt, null, ['status' => 'ACCEPTED']);
            $this->events->dispatch('ServiceAccepted', $receipt, ['number' => $receipt->number]);

            return $receipt->fresh();
        });
    }

    public function reject(ServiceReceipt $receipt, string $reason): ServiceReceipt
    {
        $receipt->forceFill(['status' => ServiceReceiptStatus::REJECTED->value, 'notes' => $reason])->save();
        $this->audit->record(ProcurementAuditAction::REJECT, $receipt, null, ['status' => 'REJECTED'], $reason);

        return $receipt->fresh();
    }
}
