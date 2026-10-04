<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Contracts\WorkflowProviderInterface;
use App\Modules\Procurement\Domain\Enums\PoAmendmentStatus;
use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Domain\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Domain\Enums\VendorConfirmationStatus;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderApproval;
use App\Modules\Procurement\Models\PurchaseOrderVersion;
use App\Modules\Procurement\Models\VendorConfirmation;
use App\Modules\Procurement\Models\VendorSelection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Order (§17–§21).
 *
 * Aturan penting yang ditegakkan di sini:
 *  - PO tidak boleh terbit sebelum disetujui (§52.2).
 *  - PO yang sudah terbit tidak boleh disunting langsung — hanya via amandemen
 *    berversi (§52.3, §52.4).
 *  - Vendor harus layak (§52.16).
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly ProcurementNumberingService $numbering,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementEventService $events,
        private readonly ProcurementControlService $control,
        private readonly VendorService $vendors,
        private readonly BudgetService $budget,
        private readonly WorkflowProviderInterface $workflow,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return PurchaseOrder::query()
            ->with(['vendor:id,code,legal_name', 'items', 'latestConfirmation'])
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['vendor_id'] ?? null, fn ($q, $id) => $q->where('vendor_id', (int) $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('po_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('po_date', '<=', $to))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** Buat PO dari pemilihan vendor (§17). @param array<string, mixed> $data */
    public function createFromSelection(int $selectionId, array $data = []): PurchaseOrder
    {
        $selection = VendorSelection::query()->with('selectedQuotation.items')->findOrFail($selectionId);
        $quotation = $selection->selectedQuotation;

        $items = $quotation->items->map(fn ($item): array => [
            'item_id' => $item->item_id,
            'description' => $item->description,
            'specification' => $item->specification,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'unit_price' => (float) $item->unit_price,
            'discount_amount' => (float) $item->discount_amount,
            'tax_amount' => (float) $item->tax_amount,
        ])->all();

        return $this->create($data + [
            'vendor_id' => $selection->selected_vendor_id,
            'event_id' => $selection->event_id,
            'rfq_id' => $selection->rfq_id,
            'quotation_id' => $selection->selected_quotation_id,
            'selection_id' => $selection->id,
            'currency' => $quotation->currency,
            'payment_terms' => $quotation->payment_terms,
            'items' => $items,
        ], (bool) ($data['overridden'] ?? false));
    }

    /** Buat PO langsung (pembelian langsung / darurat). @param array<string, mixed> $data */
    public function create(array $data, bool $vendorOverridden = false): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $vendorOverridden): PurchaseOrder {
            $items = (array) ($data['items'] ?? []);
            unset($data['items'], $data['overridden']);

            $vendor = \App\Modules\Procurement\Models\Vendor::query()->findOrFail($data['vendor_id']);
            $this->vendors->assertUsable($vendor, $vendorOverridden);

            $data['number'] ??= $this->numbering->next(ProcurementNumberingService::CODE_PO);
            $data['status'] ??= PurchaseOrderStatus::DRAFT->value;
            $data['po_date'] ??= now()->toDateString();
            $data['current_version'] ??= 1;

            $order = PurchaseOrder::query()->create($data);
            $this->replaceItems($order, $items);
            $this->recalculate($order);
            $this->snapshot($order, 'Versi awal', false, PoAmendmentStatus::APPLIED->value);

            $this->audit->record(ProcurementAuditAction::CREATE, $order, null, $order->toArray());
            $this->events->dispatch('PurchaseOrderCreated', $order, ['number' => $order->number, 'vendor_id' => $order->vendor_id]);

            return $order->fresh(['items', 'vendor']);
        });
    }

    /** Ajukan PO untuk persetujuan (§19). */
    public function submitForApproval(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->statusEnum()->isFinal()) {
            throw ProcurementException::because('PO sudah final dan tidak dapat diajukan.', 'PO_FINAL');
        }

        return DB::transaction(function () use ($order): PurchaseOrder {
            $instance = $this->workflow->start((string) config('procurement.workflow.purchase_order', 'purchase-order'), [
                'subject' => 'purchase_order',
                'subject_id' => $order->id,
                'amount' => (float) $order->grand_total,
                'vendor_id' => $order->vendor_id,
            ]);

            $order->forceFill([
                'status' => PurchaseOrderStatus::PENDING_APPROVAL->value,
                'workflow_instance_id' => $instance['instance_id'] ?? null,
            ])->save();

            $order->approvals()->delete();

            foreach ($this->control->approvalSteps((float) $order->grand_total) as $index => $role) {
                PurchaseOrderApproval::query()->create([
                    'purchase_order_id' => $order->id,
                    'step' => $index + 1,
                    'role' => $role,
                    'status' => 'PENDING',
                ]);
            }

            $this->audit->record(ProcurementAuditAction::SUBMIT, $order, null, ['status' => 'PENDING_APPROVAL']);

            return $order->fresh('approvals');
        });
    }

    public function approve(PurchaseOrder $order, ?int $approverId, ?string $comment = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $approverId, $comment): PurchaseOrder {
            $step = $order->approvals()->where('status', 'PENDING')->orderBy('step')->first();

            if ($step !== null) {
                $step->forceFill(['status' => 'APPROVED', 'approver_id' => $approverId, 'decision_at' => now(), 'comment' => $comment])->save();
            }

            if (! $order->approvals()->where('status', 'PENDING')->exists()) {
                $order->forceFill(['status' => PurchaseOrderStatus::APPROVED->value])->save();
                $this->audit->record(ProcurementAuditAction::APPROVE, $order, null, ['status' => 'APPROVED']);
                $this->events->dispatch('PurchaseOrderApproved', $order, ['number' => $order->number]);
            }

            return $order->fresh('approvals');
        });
    }

    public function reject(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        $order->forceFill(['status' => PurchaseOrderStatus::REJECTED->value])->save();
        $this->audit->record(ProcurementAuditAction::REJECT, $order, null, ['status' => 'REJECTED'], $reason);

        return $order->fresh();
    }

    /** Terbitkan PO — hanya bila sudah disetujui (§52.2). */
    public function issue(PurchaseOrder $order, bool $budgetOverridden = false): PurchaseOrder
    {
        if ($order->statusEnum() !== PurchaseOrderStatus::APPROVED) {
            throw ProcurementException::because('PO hanya dapat diterbitkan setelah disetujui.', 'PO_NOT_APPROVED');
        }

        return DB::transaction(function () use ($order, $budgetOverridden): PurchaseOrder {
            $this->budget->assertAvailable($order, $budgetOverridden);

            $order->forceFill([
                'status' => PurchaseOrderStatus::ISSUED->value,
                'issued_at' => now(),
            ])->save();

            $this->budget->commitForOrder($order);

            $this->audit->record(ProcurementAuditAction::ISSUE, $order, null, ['status' => 'ISSUED']);
            $this->events->dispatch('PurchaseOrderIssued', $order, ['number' => $order->number]);

            return $order->fresh();
        });
    }

    /**
     * Amandemen PO (§20). Histori tidak pernah ditimpa: setiap perubahan
     * menghasilkan versi baru; perubahan material memicu persetujuan ulang.
     *
     * @param array<string, mixed> $data {items?, expected_delivery_date?, payment_terms?, ...}
     */
    public function amend(PurchaseOrder $order, array $data, string $reason, bool $material = false, ?int $userId = null): PurchaseOrderVersion
    {
        return DB::transaction(function () use ($order, $data, $reason, $material, $userId): PurchaseOrderVersion {
            if ($order->statusEnum()->isFinal()) {
                throw ProcurementException::because('PO final tidak dapat diamandemen.', 'PO_FINAL');
            }

            $oldSnapshot = $this->snapshotData($order);

            $items = array_key_exists('items', $data) ? (array) $data['items'] : null;
            unset($data['items']);

            $order->fill($data)->save();

            if ($items !== null) {
                $this->replaceItems($order, $items);
            }

            $this->recalculate($order);
            $newSnapshot = $this->snapshotData($order);

            $changed = $this->diff($oldSnapshot, $newSnapshot);

            $version = $this->snapshot(
                $order,
                $reason,
                $material,
                $material ? \App\Modules\Procurement\Domain\Enums\PoAmendmentStatus::PENDING_APPROVAL->value : \App\Modules\Procurement\Domain\Enums\PoAmendmentStatus::APPLIED->value,
                $changed,
                $userId,
            );

            $order->forceFill(['current_version' => $version->version])->save();

            if ($material) {
                $order->forceFill(['status' => PurchaseOrderStatus::PENDING_APPROVAL->value])->save();
            }

            $this->audit->record(ProcurementAuditAction::AMEND, $order, $oldSnapshot, $newSnapshot, $reason);
            $this->events->dispatch('PurchaseOrderAmended', $order, ['version' => $version->version, 'reason' => $reason]);

            return $version;
        });
    }

    /** Konfirmasi vendor atas PO (§21). @param array<string, mixed> $data */
    public function confirm(PurchaseOrder $order, array $data, ?int $userId = null): VendorConfirmation
    {
        $confirmation = VendorConfirmation::query()->create([
            'purchase_order_id' => $order->id,
            'status' => $data['status'] ?? VendorConfirmationStatus::ACCEPTED->value,
            'confirmed_by' => $userId ?? \Illuminate\Support\Facades\Auth::id(),
            'confirmed_at' => now(),
            'revised_delivery_date' => $data['revised_delivery_date'] ?? null,
            'revised_notes' => $data['revised_notes'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $order->forceFill([
            'status' => PurchaseOrderStatus::VENDOR_CONFIRMED->value,
            'confirmed_at' => now(),
        ])->save();

        $this->audit->record(ProcurementAuditAction::UPDATE, $order, null, ['status' => 'VENDOR_CONFIRMED', 'confirmation' => $confirmation->status]);
        $this->events->dispatch('PurchaseOrderConfirmed', $order, ['status' => $confirmation->status instanceof \BackedEnum ? $confirmation->status->value : $confirmation->status]);

        return $confirmation;
    }

    public function close(PurchaseOrder $order): PurchaseOrder
    {
        $order->forceFill(['status' => PurchaseOrderStatus::CLOSED->value])->save();
        $this->budget->releaseForOrder($order, 'PO ditutup');
        $this->audit->record(ProcurementAuditAction::CLOSE, $order, null, ['status' => 'CLOSED']);

        return $order->fresh();
    }

    public function cancel(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        if ($order->goodsReceipts()->exists()) {
            throw ProcurementException::because('PO yang sudah memiliki penerimaan tidak dapat dibatalkan.', 'PO_HAS_RECEIPTS');
        }

        $order->forceFill(['status' => PurchaseOrderStatus::CANCELLED->value])->save();
        $this->budget->releaseForOrder($order, $reason);
        $this->audit->record(ProcurementAuditAction::CANCEL, $order, null, ['status' => 'CANCELLED'], $reason);

        return $order->fresh();
    }

    /** Perbarui kuantitas diterima pada baris PO (§23, §61). */
    public function applyReceipt(PurchaseOrder $order, array $receivedByItemId): void
    {
        foreach ($receivedByItemId as $itemId => $quantity) {
            $item = $order->items()->find($itemId);

            if ($item === null) {
                continue;
            }

            $item->forceFill([
                'received_quantity' => round((float) $item->received_quantity + (float) $quantity, 4),
            ])->save();
        }

        $this->refreshReceiptStatus($order);
    }

    public function refreshReceiptStatus(PurchaseOrder $order): PurchaseOrder
    {
        $order->loadMissing('items');

        $fullyReceived = $order->items->every(fn ($item) => (float) $item->received_quantity >= (float) $item->quantity);
        $anyReceived = $order->items->contains(fn ($item) => (float) $item->received_quantity > 0);

        if ($fullyReceived && $order->items->isNotEmpty()) {
            $order->forceFill(['status' => PurchaseOrderStatus::FULLY_RECEIVED->value])->save();
        } elseif ($anyReceived) {
            $order->forceFill(['status' => PurchaseOrderStatus::PARTIALLY_RECEIVED->value])->save();
        }

        return $order->fresh();
    }

    /** @param array<int, array<string, mixed>> $items */
    protected function replaceItems(PurchaseOrder $order, array $items): void
    {
        $order->items()->delete();

        foreach ($items as $row) {
            $quantity = (float) ($row['quantity'] ?? 0);
            $unitPrice = (float) ($row['unit_price'] ?? 0);
            $discount = (float) ($row['discount_amount'] ?? 0);
            $tax = (float) ($row['tax_amount'] ?? 0);
            $subtotal = round($quantity * $unitPrice, 2);

            $order->items()->create([
                'item_id' => $row['item_id'] ?? null,
                'purchase_request_item_id' => $row['purchase_request_item_id'] ?? null,
                'description' => $row['description'] ?? '-',
                'specification' => $row['specification'] ?? null,
                'item_type' => $row['item_type'] ?? 'GOODS',
                'quantity' => $quantity,
                'unit' => $row['unit'] ?? 'unit',
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'subtotal' => $subtotal,
                'total' => round($subtotal - $discount + $tax, 2),
                'required_delivery_date' => $row['required_delivery_date'] ?? null,
                'warehouse_id' => $row['warehouse_id'] ?? null,
                'is_asset' => (bool) ($row['is_asset'] ?? false),
                'is_inventory' => (bool) ($row['is_inventory'] ?? false),
                'project_reference' => $row['project_reference'] ?? null,
                'cost_center_id' => $row['cost_center_id'] ?? null,
            ]);
        }
    }

    protected function recalculate(PurchaseOrder $order): void
    {
        $order->loadMissing('items');

        $subtotal = round((float) $order->items->sum('subtotal'), 2);
        $itemsTotal = round((float) $order->items->sum('total'), 2);

        $order->forceFill([
            'subtotal' => $subtotal,
            'grand_total' => round($itemsTotal - (float) $order->discount_amount + (float) $order->tax_amount + (float) $order->shipping_amount, 2),
        ])->save();
    }

    /** @return array<string, mixed> */
    protected function snapshotData(PurchaseOrder $order): array
    {
        $order->loadMissing('items');

        return [
            'header' => $order->only([
                'number', 'vendor_id', 'expected_delivery_date', 'currency', 'payment_terms', 'delivery_terms',
                'subtotal', 'discount_amount', 'tax_amount', 'shipping_amount', 'grand_total', 'status',
            ]),
            'items' => $order->items->map(fn ($item): array => $item->only([
                'id', 'description', 'quantity', 'unit', 'unit_price', 'discount_amount', 'tax_amount', 'subtotal', 'total',
            ]))->all(),
        ];
    }

    /** @param array<string, mixed> $changed */
    protected function snapshot(
        PurchaseOrder $order,
        string $reason,
        bool $material,
        string $status,
        array $changed = [],
        ?int $userId = null,
    ): PurchaseOrderVersion {
        $version = (int) $order->versions()->max('version') + 1;

        return PurchaseOrderVersion::query()->create([
            'purchase_order_id' => $order->id,
            'version' => $version,
            'snapshot' => $this->snapshotData($order),
            'reason' => $reason,
            'requested_by' => $userId ?? \Illuminate\Support\Facades\Auth::id(),
            'status' => $status,
            'is_material' => $material,
            'changed_fields' => $changed['fields'] ?? null,
            'old_values' => $changed['old'] ?? null,
            'new_values' => $changed['new'] ?? null,
        ]);
    }

    /** @return array{fields: list<string>, old: array<string, mixed>, new: array<string, mixed>} */
    protected function diff(array $old, array $new): array
    {
        $fields = [];
        $oldValues = [];
        $newValues = [];

        foreach ($new['header'] as $key => $value) {
            if (($old['header'][$key] ?? null) !== $value) {
                $fields[] = $key;
                $oldValues[$key] = $old['header'][$key] ?? null;
                $newValues[$key] = $value;
            }
        }

        if (($old['items'] ?? []) !== ($new['items'] ?? [])) {
            $fields[] = 'items';
            $oldValues['items'] = $old['items'] ?? [];
            $newValues['items'] = $new['items'] ?? [];
        }

        return ['fields' => $fields, 'old' => $oldValues, 'new' => $newValues];
    }
}
