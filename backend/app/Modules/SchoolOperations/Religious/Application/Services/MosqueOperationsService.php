<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\{
    AssetGatewayInterface, FacilityGatewayInterface, FinanceGatewayInterface, InventoryGatewayInterface
};
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{
    Mosque, MosqueAssetAssignment, MosqueCleaningTask, MosqueDonationCollection, MosqueEquipment, MosqueMaintenanceRequest
};
use Illuminate\Support\Facades\DB;

/**
 * Operasional masjid (§61, §62, §63).
 *
 * Modul ini hanya MEMBUAT permintaan operasional; pemeliharaan, aset, dan
 * keuangan tetap dikerjakan modulnya masing-masing.
 */
class MosqueOperationsService
{
    public function __construct(
        protected ReligiousContext $context,
        protected AssetGatewayInterface $assets,
        protected FacilityGatewayInterface $facilities,
        protected InventoryGatewayInterface $inventory,
        protected FinanceGatewayInterface $finance,
    ) {
    }

    // ── Kebersihan (§61) ────────────────────────────────────────────────

    public function createCleaningTask(int $mosqueId, array $payload): MosqueCleaningTask
    {
        $task = MosqueCleaningTask::query()->create([
            'mosque_id' => $mosqueId,
            'area_id' => $payload['area_id'] ?? null,
            'code' => $payload['code'] ?? $this->context->nextCode('CLN', 'mosque_cleaning_tasks'),
            'task_type' => strtoupper((string) ($payload['task_type'] ?? 'DAILY')),
            'scheduled_date' => $payload['scheduled_date'] ?? now()->toDateString(),
            'scheduled_time' => $payload['scheduled_time'] ?? null,
            'assigned_to' => $payload['assigned_to'] ?? null,
            'assigned_name' => $payload['assigned_name'] ?? null,
            'status' => 'PENDING',
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('religious.cleaning.task_created', $task, ['mosque_id' => $mosqueId]);

        return $task;
    }

    public function completeCleaning(int $taskId, array $payload = []): MosqueCleaningTask
    {
        $task = MosqueCleaningTask::query()->findOrFail($taskId);
        $task->forceFill([
            'status' => 'COMPLETED',
            'completed_at' => now(),
            'inspected_by' => $payload['inspected_by'] ?? null,
            'inspected_at' => isset($payload['inspected_by']) ? now() : null,
            'inspection_result' => $payload['inspection_result'] ?? null,
            'notes' => $payload['notes'] ?? $task->notes,
        ])->save();

        $this->context->audit('religious.cleaning.completed', $task, ['result' => $task->inspection_result]);

        return $task;
    }

    // ── Pemeliharaan (§62) ──────────────────────────────────────────────

    /** Laporkan kerusakan aset/perlengkapan masjid → diteruskan ke Maintenance. */
    public function reportMaintenance(int $mosqueId, array $payload): array
    {
        $request = MosqueMaintenanceRequest::query()->create([
            'mosque_id' => $mosqueId,
            'area_id' => $payload['area_id'] ?? null,
            'asset_id' => $payload['asset_id'] ?? null,
            'equipment_id' => $payload['equipment_id'] ?? null,
            'code' => $payload['code'] ?? $this->context->nextCode('MNT', 'mosque_maintenance_requests'),
            'title' => $payload['title'],
            'category' => strtoupper((string) ($payload['category'] ?? 'OTHER')),
            'severity' => strtoupper((string) ($payload['severity'] ?? 'MEDIUM')),
            'description' => $payload['description'] ?? null,
            'reported_by' => $this->context->actorId(),
            'reported_at' => now(),
            'status' => 'REPORTED',
            'notes' => $payload['notes'] ?? null,
        ]);

        $forwarded = null;
        if (! empty($payload['asset_id'])) {
            $forwarded = $this->assets->logUsage([
                'asset_id' => $payload['asset_id'],
                'action' => 'MAINTENANCE_REQUEST',
                'reference' => $request->code,
                'note' => $request->title,
                'severity' => $request->severity,
            ]);
        }

        $this->context->audit('religious.maintenance.reported', $request, [
            'mosque_id' => $mosqueId, 'severity' => $request->severity,
        ]);

        return ['request' => $request->only(['id', 'code', 'title', 'category', 'severity', 'status']), 'forwarded' => $forwarded];
    }

    public function resolveMaintenance(int $requestId, array $payload = []): MosqueMaintenanceRequest
    {
        $request = MosqueMaintenanceRequest::query()->findOrFail($requestId);
        $request->forceFill([
            'status' => 'RESOLVED',
            'resolved_at' => now(),
            'cost' => $payload['cost'] ?? $request->cost,
            'maintenance_reference' => $payload['maintenance_reference'] ?? $request->maintenance_reference,
            'notes' => $payload['notes'] ?? $request->notes,
        ])->save();

        if ($request->equipment_id) {
            MosqueEquipment::query()->whereKey($request->equipment_id)->update([
                'condition' => 'GOOD', 'last_checked_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->context->audit('religious.maintenance.resolved', $request, ['cost' => $request->cost]);

        return $request;
    }

    /** Daftar permintaan pemeliharaan terbuka (§62). */
    public function openMaintenance(?int $mosqueId = null): array
    {
        return MosqueMaintenanceRequest::query()
            ->when($mosqueId, fn ($q) => $q->where('mosque_id', $mosqueId))
            ->whereIn('status', ['REPORTED', 'IN_PROGRESS'])->orderByDesc('reported_at')->limit(200)->get()
            ->map(fn (MosqueMaintenanceRequest $r): array => [
                'id' => $r->getKey(), 'code' => $r->code, 'title' => $r->title,
                'category' => $r->category, 'severity' => $r->severity,
                'asset_id' => $r->asset_id, 'status' => $r->status,
                'reported_at' => $r->reported_at?->toIso8601String(),
            ])->all();
    }

    /** Permintaan inventaris (mis. Al-Qur'an, mukena, karpet) (§8). */
    public function requestInventory(array $payload): array
    {
        $result = $this->inventory->issue([
            'item_id' => $payload['item_id'] ?? null,
            'quantity' => (int) ($payload['quantity'] ?? 1),
            'reference' => $payload['reference'] ?? null,
            'reason' => $payload['reason'] ?? 'Kebutuhan masjid',
            'requested_by' => $this->context->actorId(),
        ]);

        $this->context->audit('religious.inventory.requested', null, $payload + ['result' => $result]);

        return $result;
    }

    // ── Kotak donasi (§63) ──────────────────────────────────────────────

    /** Buka kotak donasi; keuangan tetap milik Finance (§63). */
    public function openDonationBox(int $mosqueId, array $payload): MosqueDonationCollection
    {
        $collection = MosqueDonationCollection::query()->create([
            'mosque_id' => $mosqueId,
            'box_code' => $payload['box_code'],
            'collection_date' => $payload['collection_date'] ?? now()->toDateString(),
            'opened_by' => $this->context->actorId(),
            'amount' => null,
            'currency' => $payload['currency'] ?? 'IDR',
            'status' => 'OPEN',
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('religious.donation.opened', $collection, ['mosque_id' => $mosqueId]);

        return $collection;
    }

    /** Tutup & setor hasil kotak donasi ke modul keuangan. */
    public function closeDonationBox(int $collectionId, array $payload): array
    {
        $collection = MosqueDonationCollection::query()->findOrFail($collectionId);

        $collection->forceFill([
            'closed_by' => $this->context->actorId(),
            'verified_by' => $payload['verified_by'] ?? $this->context->actorId(),
            'amount' => $payload['amount'] ?? null,
            'status' => 'CLOSED',
            'notes' => $payload['notes'] ?? $collection->notes,
        ])->save();

        $posting = null;
        if ($collection->amount) {
            $posting = $this->finance->postCollection([
                'source' => 'MOSQUE_DONATION_BOX',
                'reference' => $collection->box_code.'-'.$collection->collection_date?->toDateString(),
                'amount' => $collection->amount,
                'currency' => $collection->currency,
                'mosque_id' => $collection->mosque_id,
                'date' => (string) $collection->collection_date,
            ]);

            if (! empty($posting['reference'])) {
                $collection->forceFill(['finance_reference' => $posting['reference']])->save();
            }
        }

        $this->context->audit('religious.donation.closed', $collection, ['finance' => $posting]);

        return [
            'collection' => $collection->only(['id', 'box_code', 'amount', 'status', 'finance_reference']),
            'finance_posting' => $posting,
            'note' => 'Finance tetap sumber kebenaran transaksi keuangan (§63).',
        ];
    }

    /** Ringkasan operasional masjid (§71, §72). */
    public function operationalSummary(int $mosqueId, string $from, string $to): array
    {
        return [
            // Catatan: kolom tanggal dapat tersimpan sebagai datetime, sehingga
            // perbandingan memakai whereDate agar tidak terpotong oleh komponen jam.
            'cleaning_tasks' => (int) DB::table('mosque_cleaning_tasks')->where('mosque_id', $mosqueId)
                ->whereDate('scheduled_date', '>=', $from)->whereDate('scheduled_date', '<=', $to)->count(),
            'cleaning_completed' => (int) DB::table('mosque_cleaning_tasks')->where('mosque_id', $mosqueId)
                ->whereDate('scheduled_date', '>=', $from)->whereDate('scheduled_date', '<=', $to)
                ->where('status', 'COMPLETED')->count(),
            'maintenance_reported' => (int) DB::table('mosque_maintenance_requests')->where('mosque_id', $mosqueId)
                ->whereBetween('reported_at', [$from.' 00:00:00', $to.' 23:59:59'])->count(),
            'maintenance_open' => (int) DB::table('mosque_maintenance_requests')->where('mosque_id', $mosqueId)
                ->whereIn('status', ['REPORTED', 'IN_PROGRESS'])->count(),
            'assets_assigned' => MosqueAssetAssignment::query()->where('mosque_id', $mosqueId)
                ->where('status', 'ACTIVE')->count(),
            'equipment_attention' => MosqueEquipment::query()->where('mosque_id', $mosqueId)
                ->whereIn('condition', ['DAMAGED', 'NEEDS_REPAIR'])->count(),
            'donation_collections' => (int) DB::table('mosque_donation_collections')->where('mosque_id', $mosqueId)
                ->whereDate('collection_date', '>=', $from)->whereDate('collection_date', '<=', $to)->count(),
            'donation_amount' => (float) DB::table('mosque_donation_collections')->where('mosque_id', $mosqueId)
                ->whereDate('collection_date', '>=', $from)->whereDate('collection_date', '<=', $to)->sum('amount'),
        ];
    }
}
