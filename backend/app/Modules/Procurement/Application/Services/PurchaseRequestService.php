<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Procurement\Domain\Contracts\WorkflowProviderInterface;
use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Domain\Enums\ProcurementMethod;
use App\Modules\Procurement\Domain\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Domain\Enums\PurchaseRequestStatus;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\Contract;
use App\Modules\Procurement\Models\ProcurementEvent;
use App\Modules\Procurement\Models\PurchaseOrderItem;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Models\PurchaseRequestApproval;
use App\Modules\Procurement\Models\PurchaseRequestItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Request (§8–§11).
 *
 * PR adalah dokumen kebutuhan internal. Validasi (§9) memberi **informasi**
 * (stok tersedia, PO terbuka, kontrak) — keputusan tetap di alur pengadaan,
 * bukan dipaksakan oleh sistem.
 */
class PurchaseRequestService
{
    public function __construct(
        private readonly ProcurementNumberingService $numbering,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementEventService $events,
        private readonly ProcurementControlService $control,
        private readonly InventoryProviderInterface $inventory,
        private readonly WorkflowProviderInterface $workflow,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return PurchaseRequest::query()
            ->with(['requester:id,name', 'items', 'approvals'])
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($filters['requester_id'] ?? null, fn ($q, $id) => $q->where('requester_id', (int) $id))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', (int) $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): PurchaseRequest
    {
        return DB::transaction(function () use ($data): PurchaseRequest {
            $items = (array) ($data['items'] ?? []);
            unset($data['items']);

            $data['number'] ??= $this->numbering->next(ProcurementNumberingService::CODE_PR);
            $data['status'] ??= PurchaseRequestStatus::DRAFT->value;

            $method = $this->control->resolveMethod($this->sumItems($items), $this->asMethod($data['method'] ?? null));
            $data['method'] = $method->value;
            $data['estimated_total'] = $this->sumItems($items);

            $request = PurchaseRequest::query()->create($data);
            $this->replaceItems($request, $items);

            $this->audit->record(ProcurementAuditAction::CREATE, $request, null, $request->toArray());
            $this->events->dispatch('PurchaseRequestCreated', $request, ['number' => $request->number]);

            return $request->fresh(['items', 'requester']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(PurchaseRequest $request, array $data): PurchaseRequest
    {
        if (! $request->isEditable()) {
            throw ProcurementException::because('PR yang sudah diajukan tidak dapat disunting langsung.', 'PR_NOT_EDITABLE');
        }

        return DB::transaction(function () use ($request, $data): PurchaseRequest {
            $old = $request->toArray();
            $items = array_key_exists('items', $data) ? (array) $data['items'] : null;
            unset($data['items']);

            $request->fill($data);

            if ($items !== null) {
                $request->estimated_total = $this->sumItems($items);
                $request->save();
                $this->replaceItems($request, $items);
            } else {
                $request->save();
            }

            $this->audit->record(ProcurementAuditAction::UPDATE, $request, $old, $request->fresh()->toArray());

            return $request->fresh(['items']);
        });
    }

    /**
     * Validasi PR (§9) — mengembalikan daftar temuan, tidak memblokir.
     *
     * @return array{issues: list<array{code: string, severity: string, message: string, data?: array<string, mixed>}>, suggestions: array<int, array<string, mixed>>}
     */
    public function validate(PurchaseRequest $request): array
    {
        $request->loadMissing('items');
        $issues = [];
        $suggestions = [];

        // Duplikat: PR lain dari pemohon yang sama dengan item serupa, belum final.
        $duplicate = PurchaseRequest::query()
            ->where('id', '!=', $request->id)
            ->where('requester_id', $request->requester_id)
            ->whereNotIn('status', [PurchaseRequestStatus::CLOSED->value, PurchaseRequestStatus::CANCELLED->value])
            ->whereDate('created_at', '>=', now()->subDays(30)->toDateString())
            ->whereHas('items', fn ($q) => $q->whereIn('description', $request->items->pluck('description')->all()))
            ->count();

        if ($duplicate > 0) {
            $issues[] = [
                'code' => 'DUPLICATE_REQUEST',
                'severity' => 'WARNING',
                'message' => "Terdapat {$duplicate} PR lain dengan item serupa dalam 30 hari terakhir.",
            ];
        }

        foreach ($request->items as $item) {
            // Stok tersedia (bila item inventory).
            if ($item->is_inventory && filled($item->item->inventory_item_ref ?? null)) {
                $stock = $this->inventory->stockOnHand((string) $item->item->inventory_item_ref);

                if ($stock !== null) {
                    $available = (float) ($stock['quantity'] ?? 0);
                    $suggested = max(0, round((float) $item->quantity - $available, 4));

                    $suggestions[] = [
                        'item' => $item->description,
                        'requested' => (float) $item->quantity,
                        'available_stock' => $available,
                        'suggested_procurement' => $suggested,
                    ];
                }
            }

            // PO terbuka untuk item yang sama.
            $openPo = PurchaseOrderItem::query()
                ->where('item_id', $item->item_id)
                ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', [
                    PurchaseOrderStatus::ISSUED->value,
                    PurchaseOrderStatus::VENDOR_CONFIRMED->value,
                    PurchaseOrderStatus::PARTIALLY_RECEIVED->value,
                ]))
                ->exists();

            if ($openPo) {
                $issues[] = [
                    'code' => 'OPEN_PO_EXISTS',
                    'severity' => 'INFO',
                    'message' => "Item \"{$item->description}\" masih memiliki PO terbuka.",
                ];
            }
        }

        // Kontrak aktif yang menaungi kategori.
        $contractCount = Contract::query()->active()
            ->when($request->items->first()?->category_id, fn ($q, $id) => $q->where('category_id', $id))
            ->count();

        if ($contractCount > 0) {
            $issues[] = [
                'code' => 'CONTRACT_AVAILABLE',
                'severity' => 'INFO',
                'message' => "Tersedia {$contractCount} kontrak aktif; pertimbangkan pembelian via kontrak.",
            ];
        }

        return ['issues' => $issues, 'suggestions' => $suggestions];
    }

    /** Ajukan PR untuk persetujuan (§10). */
    public function submit(PurchaseRequest $request): PurchaseRequest
    {
        if (! $request->isEditable()) {
            throw ProcurementException::because('Hanya PR berstatus draf/dikembalikan yang dapat diajukan.', 'PR_NOT_SUBMITTABLE');
        }

        return DB::transaction(function () use ($request): PurchaseRequest {
            $request->loadMissing('items');

            if ($request->items->isEmpty()) {
                throw ProcurementException::because('PR tanpa item tidak dapat diajukan.', 'PR_EMPTY');
            }

            $instance = $this->workflow->start((string) config('procurement.workflow.purchase_request', 'purchase-request'), [
                'subject' => 'purchase_request',
                'subject_id' => $request->id,
                'amount' => (float) $request->estimated_total,
                'department_id' => $request->department_id,
                'requester_id' => $request->requester_id,
            ]);

            $request->forceFill([
                'status' => PurchaseRequestStatus::SUBMITTED->value,
                'submitted_at' => now(),
                'workflow_instance_id' => $instance['instance_id'] ?? null,
            ])->save();

            $this->seedApprovalSteps($request);

            $this->audit->record(ProcurementAuditAction::SUBMIT, $request, ['status' => 'DRAFT'], ['status' => 'SUBMITTED']);
            $this->events->dispatch('PurchaseRequestSubmitted', $request, ['number' => $request->number]);

            return $request->fresh(['items', 'approvals']);
        });
    }

    /** Setujui PR (langkah saat ini atau seluruhnya bila tanpa mesin workflow). */
    public function approve(PurchaseRequest $request, ?int $approverId, ?string $comment = null): PurchaseRequest
    {
        return DB::transaction(function () use ($request, $approverId, $comment): PurchaseRequest {
            $step = $request->approvals()->where('status', 'PENDING')->orderBy('step')->first();

            if ($step !== null) {
                $step->forceFill([
                    'status' => 'APPROVED',
                    'approver_id' => $approverId,
                    'decision_at' => now(),
                    'comment' => $comment,
                ])->save();
            }

            $pending = $request->approvals()->where('status', 'PENDING')->exists();

            if (! $pending) {
                $request->forceFill([
                    'status' => PurchaseRequestStatus::APPROVED->value,
                    'approved_at' => now(),
                    'approved_total' => $request->estimated_total,
                ])->save();

                $this->audit->record(ProcurementAuditAction::APPROVE, $request, null, ['status' => 'APPROVED']);
                $this->events->dispatch('PurchaseRequestApproved', $request, ['number' => $request->number]);
            }

            return $request->fresh(['approvals']);
        });
    }

    public function reject(PurchaseRequest $request, ?int $approverId, string $reason): PurchaseRequest
    {
        $request->forceFill(['status' => PurchaseRequestStatus::REJECTED->value])->save();

        $this->audit->record(ProcurementAuditAction::REJECT, $request, null, ['status' => 'REJECTED'], $reason);
        $this->events->dispatch('PurchaseRequestRejected', $request, ['reason' => $reason]);

        return $request->fresh();
    }

    public function return(PurchaseRequest $request, string $reason): PurchaseRequest
    {
        $request->forceFill(['status' => PurchaseRequestStatus::RETURNED->value])->save();

        $this->audit->record(ProcurementAuditAction::RETURN, $request, null, ['status' => 'RETURNED'], $reason);
        $this->events->dispatch('PurchaseRequestReturned', $request, ['reason' => $reason]);

        return $request->fresh();
    }

    /**
     * Konsolidasi beberapa PR menjadi satu peristiwa pengadaan (§11).
     *
     * @param  array<int, int>  $requestIds
     */
    public function consolidate(array $requestIds, string $title, ?int $userId = null): ProcurementEvent
    {
        return DB::transaction(function () use ($requestIds, $title, $userId): ProcurementEvent {
            $requests = PurchaseRequest::query()->whereIn('id', $requestIds)->get();

            if ($requests->isEmpty()) {
                throw ProcurementException::because('Tidak ada PR yang dapat dikonsolidasikan.', 'NO_REQUESTS');
            }

            $event = ProcurementEvent::query()->create([
                'number' => $this->numbering->next(ProcurementNumberingService::CODE_EVENT),
                'title' => $title,
                'status' => 'OPEN',
                'method' => $requests->first()->method instanceof \BackedEnum ? $requests->first()->method->value : $requests->first()->method,
                'requested_by' => $userId,
                'estimated_total' => $requests->sum(fn ($r) => (float) $r->estimated_total),
                'created_by' => $userId,
            ]);

            $event->requests()->sync($requests->pluck('id')->all());

            $this->audit->record(ProcurementAuditAction::CREATE, $event, null, $event->toArray(), 'Konsolidasi PR');

            return $event->fresh('requests');
        });
    }

    /**
     * Dipakai adapter Asset (ProcurementProviderInterface::request).
     *
     * @param array<string, mixed> $payload
     */
    public function createFromExternal(array $payload): PurchaseRequest
    {
        $items = (array) ($payload['items'] ?? []);

        if ($items === [] && isset($payload['description'])) {
            $items = [[
                'description' => $payload['description'],
                'quantity' => $payload['quantity'] ?? 1,
                'unit' => $payload['unit'] ?? 'unit',
                'estimated_unit_price' => $payload['estimated_unit_price'] ?? 0,
                'is_asset' => true,
                'item_type' => $payload['item_type'] ?? 'GOODS',
            ]];
        }

        return $this->create([
            'requester_id' => $payload['requester_id'] ?? \Illuminate\Support\Facades\Auth::id(),
            'department_id' => $payload['department_id'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
            'required_date' => $payload['required_date'] ?? now()->addDays(14)->toDateString(),
            'purpose' => $payload['purpose'] ?? 'Permintaan dari modul lain',
            'justification' => $payload['justification'] ?? null,
            'priority' => $payload['priority'] ?? 'NORMAL',
            'currency' => $payload['currency'] ?? 'IDR',
            'created_by' => \Illuminate\Support\Facades\Auth::id(),
            'items' => $items,
        ]);
    }

    /** @param array<int, array<string, mixed>> $items */
    protected function replaceItems(PurchaseRequest $request, array $items): void
    {
        $request->items()->delete();

        foreach ($items as $row) {
            $quantity = (float) ($row['quantity'] ?? 0);
            $unitPrice = (float) ($row['estimated_unit_price'] ?? 0);

            $request->items()->create([
                'item_id' => $row['item_id'] ?? null,
                'description' => $row['description'] ?? '-',
                'specification' => $row['specification'] ?? null,
                'item_type' => $row['item_type'] ?? 'GOODS',
                'quantity' => $quantity,
                'unit' => $row['unit'] ?? 'unit',
                'estimated_unit_price' => $unitPrice,
                'estimated_total' => round($quantity * $unitPrice, 2),
                'required_date' => $row['required_date'] ?? $request->required_date,
                'category_id' => $row['category_id'] ?? null,
                'is_asset' => (bool) ($row['is_asset'] ?? false),
                'is_inventory' => (bool) ($row['is_inventory'] ?? false),
                'project_reference' => $row['project_reference'] ?? $request->project_reference,
                'budget_reference' => $row['budget_reference'] ?? $request->budget_reference,
                'preferred_vendor_id' => $row['preferred_vendor_id'] ?? null,
                'notes' => $row['notes'] ?? null,
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    protected function sumItems(array $items): float
    {
        $total = 0.0;

        foreach ($items as $row) {
            $total += round((float) ($row['quantity'] ?? 0) * (float) ($row['estimated_unit_price'] ?? 0), 2);
        }

        return round($total, 2);
    }

    protected function seedApprovalSteps(PurchaseRequest $request): void
    {
        $request->approvals()->delete();

        $steps = $this->control->approvalSteps((float) $request->estimated_total);

        if ($steps === []) {
            $steps = ['department_head'];
        }

        foreach ($steps as $index => $role) {
            PurchaseRequestApproval::query()->create([
                'purchase_request_id' => $request->id,
                'step' => $index + 1,
                'role' => $role,
                'status' => 'PENDING',
            ]);
        }
    }

    protected function asMethod(?string $value): ?ProcurementMethod
    {
        return $value !== null ? ProcurementMethod::tryFrom($value) : null;
    }
}
