<?php

namespace App\Services\Finance;

use App\Models\FinancePurchaseOrder;
use App\Models\FinancePurchaseOrderItem;
use App\Models\FinancePurchaseRequest;
use App\Models\FinancePurchaseRequestItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengadaan (§37, §38): purchase request → purchase order → penerimaan barang.
 *
 * Ini alur dokumen, bukan jurnal. Nilai keuangan baru diakui saat invoice vendor
 * diterima (lihat {@see FinanceVendorInvoiceService}) yang bisa menaut ke PO —
 * sehingga tidak ada pengakuan ganda. Penerimaan barang hanya menaikkan
 * `received_quantity` dan status PO; integrasi stok fisik memakai Inventory
 * module bila tersedia (§63), Finance hanya menangani sisi nilainya.
 *
 * Pemisahan pengaju vs penyetuju (§3) dijaga: pengaju tidak boleh menyetujui
 * PR/PO-nya sendiri.
 */
class FinancePurchaseService
{
    public function __construct(
        private readonly FinanceNumberingService $numbering,
    ) {}

    // ==================== Purchase Request (§37) ====================

    /**
     * Buat purchase request beserta barisnya.
     *
     * @param  array{cost_center_id?: int|null, description?: string|null, needed_date?: Carbon|string|null,
     *               items: list<array{item_name: string, quantity?: int|null, estimated_price?: float|null,
     *               account_id?: int|null}>, submit?: bool}  $data
     */
    public function createRequest(array $data, ?User $actor = null): FinancePurchaseRequest
    {
        $items = $this->normalizeItems($data['items'] ?? [], 'estimated_price');

        return DB::transaction(function () use ($data, $items, $actor): FinancePurchaseRequest {
            $request = FinancePurchaseRequest::create([
                'request_number' => $this->numbering->next(FinanceNumberingService::CODE_PURCHASE_REQUEST),
                'requester_id' => $actor?->id,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'description' => $data['description'] ?? null,
                'needed_date' => $data['needed_date'] ?? null,
                'status' => FinancePurchaseRequest::STATUSES[0],
            ]);

            $this->syncRequestItems($request, $items);

            if (! empty($data['submit'])) {
                return $this->submitRequest($request, $actor);
            }

            return $request->load('items');
        });
    }

    public function submitRequest(FinancePurchaseRequest $request, ?User $actor = null): FinancePurchaseRequest
    {
        return DB::transaction(function () use ($request): FinancePurchaseRequest {
            $fresh = FinancePurchaseRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, ['draft', 'rejected'], true)) {
                throw ValidationException::withMessages(['status' => "Hanya PR draft/ditolak yang bisa diajukan (status: {$fresh->status})."]);
            }

            if ($fresh->items()->count() === 0) {
                throw ValidationException::withMessages(['items' => 'Purchase request tanpa baris tidak bisa diajukan.']);
            }

            $fresh->status = 'submitted';
            $fresh->reject_reason = null;
            $fresh->save();

            return $fresh->load('items');
        });
    }

    public function approveRequest(FinancePurchaseRequest $request, ?User $actor = null): FinancePurchaseRequest
    {
        return DB::transaction(function () use ($request, $actor): FinancePurchaseRequest {
            $fresh = FinancePurchaseRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'submitted') {
                throw ValidationException::withMessages(['status' => "Hanya PR berstatus diajukan yang bisa disetujui (status: {$fresh->status})."]);
            }

            $this->assertNotRequester((int) $fresh->requester_id, $actor, 'approved_by');

            $fresh->status = 'approved';
            $fresh->approved_by = $actor?->id;
            $fresh->approved_at = Carbon::now();
            $fresh->reject_reason = null;
            $fresh->save();

            return $fresh->load('items');
        });
    }

    public function rejectRequest(FinancePurchaseRequest $request, string $reason, ?User $actor = null): FinancePurchaseRequest
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reject_reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($request, $reason): FinancePurchaseRequest {
            $fresh = FinancePurchaseRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'submitted') {
                throw ValidationException::withMessages(['status' => "Hanya PR berstatus diajukan yang bisa ditolak (status: {$fresh->status})."]);
            }

            $fresh->status = 'rejected';
            $fresh->reject_reason = $reason;
            $fresh->save();

            return $fresh->load('items');
        });
    }

    public function cancelRequest(FinancePurchaseRequest $request): FinancePurchaseRequest
    {
        return DB::transaction(function () use ($request): FinancePurchaseRequest {
            $fresh = FinancePurchaseRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === 'approved') {
                throw ValidationException::withMessages(['status' => 'PR yang sudah disetujui tidak bisa dibatalkan; batalkan PO turunannya.']);
            }

            $fresh->status = 'cancelled';
            $fresh->save();

            return $fresh->load('items');
        });
    }

    // ==================== Purchase Order (§38) ====================

    /**
     * Buat PO (langsung atau dari PR yang sudah disetujui) beserta barisnya.
     *
     * @param  array{vendor_id: int, purchase_request_id?: int|null, po_date?: Carbon|string|null,
     *               cost_center_id?: int|null, account_id?: int|null, tax?: float|null, notes?: string|null,
     *               items: list<array{item_name: string, quantity?: int|null, price?: float|null}>,
     *               submit?: bool}  $data
     */
    public function createOrder(array $data, ?User $actor = null): FinancePurchaseOrder
    {
        $items = $this->normalizeItems($data['items'] ?? [], 'price');
        $tax = round((float) ($data['tax'] ?? 0), 2);

        if ($tax < 0) {
            throw ValidationException::withMessages(['tax' => 'Pajak tidak boleh negatif.']);
        }

        return DB::transaction(function () use ($data, $items, $tax, $actor): FinancePurchaseOrder {
            $prId = $data['purchase_request_id'] ?? null;

            if ($prId !== null) {
                $pr = FinancePurchaseRequest::query()->whereKey($prId)->lockForUpdate()->first();

                if ($pr === null || $pr->status !== 'approved') {
                    throw ValidationException::withMessages(['purchase_request_id' => 'PO hanya bisa dibuat dari PR yang sudah disetujui.']);
                }
            }

            $subtotal = round(array_sum(array_map(fn (array $i): float => (float) $i['total'], $items)), 2);

            $order = FinancePurchaseOrder::create([
                'po_number' => $this->numbering->next(FinanceNumberingService::CODE_PURCHASE_ORDER),
                'vendor_id' => (int) $data['vendor_id'],
                'purchase_request_id' => $prId,
                'po_date' => $data['po_date'] ?? Carbon::now()->toDateString(),
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'account_id' => $data['account_id'] ?? null,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => round($subtotal + $tax, 2),
                'status' => FinancePurchaseOrder::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $this->syncOrderItems($order, $items);

            if (! empty($data['submit'])) {
                return $this->submitOrder($order, $actor);
            }

            return $order->load('items');
        });
    }

    public function submitOrder(FinancePurchaseOrder $order, ?User $actor = null): FinancePurchaseOrder
    {
        return DB::transaction(function () use ($order): FinancePurchaseOrder {
            $fresh = $this->lockOrder($order);

            if ($fresh->status !== FinancePurchaseOrder::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => "Hanya PO draft yang bisa diajukan (status: {$fresh->status})."]);
            }

            if ($fresh->items()->count() === 0) {
                throw ValidationException::withMessages(['items' => 'PO tanpa baris tidak bisa diajukan.']);
            }

            $fresh->status = FinancePurchaseOrder::STATUS_SUBMITTED;
            $fresh->save();

            return $fresh->load('items');
        });
    }

    public function approveOrder(FinancePurchaseOrder $order, ?User $actor = null): FinancePurchaseOrder
    {
        return DB::transaction(function () use ($order, $actor): FinancePurchaseOrder {
            $fresh = $this->lockOrder($order);

            if ($fresh->status !== FinancePurchaseOrder::STATUS_SUBMITTED) {
                throw ValidationException::withMessages(['status' => "Hanya PO berstatus diajukan yang bisa disetujui (status: {$fresh->status})."]);
            }

            $this->assertNotRequester((int) $fresh->created_by, $actor, 'approved_by');

            $fresh->status = FinancePurchaseOrder::STATUS_APPROVED;
            $fresh->approved_by = $actor?->id;
            $fresh->approved_at = Carbon::now();
            $fresh->save();

            return $fresh->load('items');
        });
    }

    /** Tandai PO dipesan ke vendor (approved → ordered). */
    public function markOrdered(FinancePurchaseOrder $order): FinancePurchaseOrder
    {
        return DB::transaction(function () use ($order): FinancePurchaseOrder {
            $fresh = $this->lockOrder($order);

            if ($fresh->status !== FinancePurchaseOrder::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => "Hanya PO disetujui yang bisa ditandai dipesan (status: {$fresh->status})."]);
            }

            $fresh->status = FinancePurchaseOrder::STATUS_ORDERED;
            $fresh->save();

            return $fresh->load('items');
        });
    }

    /**
     * Terima barang (§38): naikkan `received_quantity` per baris. Status jadi
     * `partially_received` atau `received` bila semua baris penuh.
     *
     * @param  list<array{purchase_order_item_id: int, quantity: int}>  $received
     */
    public function receive(FinancePurchaseOrder $order, array $received, ?User $actor = null): FinancePurchaseOrder
    {
        if ($received === []) {
            throw ValidationException::withMessages(['items' => 'Daftar barang diterima wajib diisi.']);
        }

        return DB::transaction(function () use ($order, $received): FinancePurchaseOrder {
            $fresh = $this->lockOrder($order);

            if (! in_array($fresh->status, [FinancePurchaseOrder::STATUS_APPROVED, FinancePurchaseOrder::STATUS_ORDERED, FinancePurchaseOrder::STATUS_PARTIALLY_RECEIVED], true)) {
                throw ValidationException::withMessages(['status' => "PO berstatus {$fresh->status} tidak bisa menerima barang."]);
            }

            $items = $fresh->items()->lockForUpdate()->get()->keyBy('id');

            foreach ($received as $entry) {
                $itemId = (int) ($entry['purchase_order_item_id'] ?? 0);
                $qty = (int) ($entry['quantity'] ?? 0);

                $item = $items->get($itemId);

                if ($item === null) {
                    throw ValidationException::withMessages(['items' => "Baris PO {$itemId} tidak ditemukan."]);
                }

                if ($qty <= 0) {
                    throw ValidationException::withMessages(['items' => 'Kuantitas diterima harus lebih dari nol.']);
                }

                $newReceived = (int) $item->received_quantity + $qty;

                if ($newReceived > (int) $item->quantity) {
                    throw ValidationException::withMessages([
                        'items' => "Penerimaan {$item->item_name} melebihi kuantitas PO ({$item->quantity}).",
                    ]);
                }

                $item->received_quantity = $newReceived;
                $item->save();
            }

            $allReceived = $fresh->items()->get()->every(fn (FinancePurchaseOrderItem $i): bool => $i->isFullyReceived());

            $fresh->status = $allReceived ? FinancePurchaseOrder::STATUS_RECEIVED : FinancePurchaseOrder::STATUS_PARTIALLY_RECEIVED;

            if ($allReceived) {
                $fresh->received_at = Carbon::now();
            }

            $fresh->save();

            return $fresh->load('items');
        });
    }

    public function cancelOrder(FinancePurchaseOrder $order): FinancePurchaseOrder
    {
        return DB::transaction(function () use ($order): FinancePurchaseOrder {
            $fresh = $this->lockOrder($order);

            if (in_array($fresh->status, [FinancePurchaseOrder::STATUS_PARTIALLY_RECEIVED, FinancePurchaseOrder::STATUS_RECEIVED], true)) {
                throw ValidationException::withMessages(['status' => 'PO yang sudah menerima barang tidak bisa dibatalkan.']);
            }

            if ($fresh->vendorInvoices()->exists()) {
                throw ValidationException::withMessages(['status' => 'PO yang sudah punya invoice vendor tidak bisa dibatalkan.']);
            }

            $fresh->status = FinancePurchaseOrder::STATUS_CANCELLED;
            $fresh->save();

            return $fresh->load('items');
        });
    }

    // ==================== Internal ====================

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<array{item_name: string, quantity: int, price: float, total: float, account_id?: int|null}>
     */
    protected function normalizeItems(array $raw, string $priceKey): array
    {
        if ($raw === []) {
            throw ValidationException::withMessages(['items' => 'Minimal satu baris wajib diisi.']);
        }

        $items = [];

        foreach ($raw as $index => $row) {
            $name = trim((string) ($row['item_name'] ?? ''));
            $qty = (int) ($row['quantity'] ?? 1);
            $price = round((float) ($row[$priceKey] ?? 0), 2);

            if ($name === '') {
                throw ValidationException::withMessages(["items.{$index}.item_name" => 'Nama barang wajib diisi.']);
            }

            if ($qty <= 0) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Kuantitas harus lebih dari nol.']);
            }

            if ($price < 0) {
                throw ValidationException::withMessages(["items.{$index}.{$priceKey}" => 'Harga tidak boleh negatif.']);
            }

            $items[] = [
                'item_name' => $name,
                'quantity' => $qty,
                'price' => $price,
                'total' => round($qty * $price, 2),
                'account_id' => $row['account_id'] ?? null,
            ];
        }

        return $items;
    }

    /** @param  list<array{item_name: string, quantity: int, price: float, total: float, account_id?: int|null}>  $items */
    protected function syncRequestItems(FinancePurchaseRequest $request, array $items): void
    {
        $request->items()->delete();

        foreach ($items as $item) {
            FinancePurchaseRequestItem::create([
                'purchase_request_id' => $request->id,
                'item_name' => $item['item_name'],
                'quantity' => $item['quantity'],
                'estimated_price' => $item['price'],
                'total' => $item['total'],
                'account_id' => $item['account_id'] ?? null,
            ]);
        }
    }

    /** @param  list<array{item_name: string, quantity: int, price: float, total: float}>  $items */
    protected function syncOrderItems(FinancePurchaseOrder $order, array $items): void
    {
        $order->items()->delete();

        foreach ($items as $item) {
            FinancePurchaseOrderItem::create([
                'purchase_order_id' => $order->id,
                'item_name' => $item['item_name'],
                'quantity' => $item['quantity'],
                'received_quantity' => 0,
                'price' => $item['price'],
                'total' => $item['total'],
            ]);
        }
    }

    protected function assertNotRequester(int $requesterId, ?User $actor, string $field): void
    {
        if ($actor !== null && $requesterId === (int) $actor->id) {
            throw ValidationException::withMessages([
                $field => 'Pengaju tidak boleh menyetujui pengajuannya sendiri.',
            ]);
        }
    }

    protected function lockOrder(FinancePurchaseOrder $order): FinancePurchaseOrder
    {
        return FinancePurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }
}
