<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Contracts\FinanceProviderInterface;
use App\Modules\Asset\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Asset\Domain\Enums\AssetCriticality;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\MaintenancePriority;
use App\Modules\Asset\Domain\Enums\MaintenanceRequestStatus;
use App\Modules\Asset\Domain\Enums\TaskStatus;
use App\Modules\Asset\Domain\Enums\WorkOrderStatus;
use App\Modules\Asset\Domain\Enums\WorkOrderType;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Domain\StateMachines\AssetStateMachine;
use App\Modules\Asset\Domain\StateMachines\MaintenanceStateMachine;
use App\Modules\Asset\Domain\StateMachines\WorkOrderStateMachine;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\Facility;
use App\Modules\Asset\Models\MaintenanceLog;
use App\Modules\Asset\Models\MaintenanceRequest;
use App\Modules\Asset\Models\MaintenanceWorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pemeliharaan: permintaan → triage → work order → pengerjaan → verifikasi.
 *
 * Tiga hal yang membedakan ini dari "catatan perbaikan":
 *  - **SLA dihitung dari prioritas**, bukan diketik; tenggat keterlambatan jadi
 *    fakta, bukan pendapat.
 *  - **Suku cadang memakai Persediaan** — modul ini tidak menyimpan stok kedua.
 *  - **Biaya dipisah menurut jenis** dan dicatatkan ke Keuangan setelah selesai,
 *    sehingga pertanyaan "uangnya habis di mana" terjawab.
 */
class MaintenanceService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
        private readonly InventoryProviderInterface $inventory,
        private readonly FinanceProviderInterface $finance,
    ) {}

    /** Permintaan pemeliharaan — dapat dibuat siapa pun yang berhak melaporkan. */
    public function createRequest(User $actor, array $data): MaintenanceRequest
    {
        $asset = isset($data['asset_id']) ? Asset::query()->find($data['asset_id']) : null;
        $facility = isset($data['facility_id']) ? Facility::query()->find($data['facility_id']) : null;

        if ($asset === null && $facility === null) {
            throw new AssetValidationException(['asset_id' => ['Permintaan harus menunjuk aset atau fasilitas.']]);
        }

        $priority = $this->resolvePriority($data['priority'] ?? null, $asset);

        return DB::transaction(function () use ($actor, $data, $asset, $facility, $priority): MaintenanceRequest {
            $request = MaintenanceRequest::query()->create([
                'request_number' => $this->numbers->next('work_order', ['tahun' => now()->year]),
                'asset_id' => $asset?->id,
                'facility_id' => $facility?->id,
                'location_id' => $data['location_id'] ?? $asset?->location_id ?? $facility?->location_id,
                'requested_by' => $actor->id,
                'source' => $data['source'] ?? 'USER',
                'category' => $data['category'] ?? null,
                'priority' => $priority->value,
                'status' => MaintenanceRequestStatus::NEW->value,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'photo_path' => $data['photo_path'] ?? null,
                'reported_at' => now(),
                // SLA dihitung sekali di sini: tenggat yang dihitung ulang setiap
                // kali prioritas disentuh bukan tenggat.
                'sla_due_at' => now()->addHours($priority->responseHours()),
                'damage_report_id' => $data['damage_report_id'] ?? null,
                'cost_estimate' => $data['cost_estimate'] ?? null,
            ]);

            $this->notifications->notify(
                [],
                ['MAINTENANCE_MANAGER', 'ASSET_OFFICER'],
                'MAINTENANCE_REQUESTED',
                'Permintaan pemeliharaan baru: '.$request->title,
                sprintf('%s — prioritas %s, tenggat tanggap %s.', $request->request_number, $priority->value, $request->sla_due_at?->toDateTimeString()),
                $request,
            );

            $this->audit->record(AuditAction::MAINTENANCE_REQUESTED, $request, $actor, [
                'status_after' => $request->status,
                'reason' => $data['title'],
                'context' => ['priority' => $priority->value, 'sla_due_at' => $request->sla_due_at?->toDateTimeString()],
            ]);

            return $request->refresh();
        });
    }

    /** Triage: kategori, prioritas, dan jawaban pertama dicatat. */
    public function triage(User $actor, MaintenanceRequest $request, array $data = []): MaintenanceRequest
    {
        MaintenanceStateMachine::assert($this->requestStatus($request), MaintenanceRequestStatus::TRIAGED->value, 'permintaan');

        $priority = isset($data['priority']) ? MaintenancePriority::from((string) $data['priority']) : $this->priorityOf($request);

        $request->forceFill([
            'status' => MaintenanceRequestStatus::TRIAGED->value,
            'category' => $data['category'] ?? $request->category,
            'priority' => $priority->value,
            'triaged_by' => $actor->id,
            'triaged_at' => now(),
            'responded_at' => $request->responded_at ?? now(),
            'notes' => $data['notes'] ?? $request->notes,
        ])->save();

        $this->audit->record(AuditAction::MAINTENANCE_TRIAGED, $request, $actor, [
            'status_before' => MaintenanceRequestStatus::NEW->value,
            'status_after' => MaintenanceRequestStatus::TRIAGED->value,
            'reason' => $data['notes'] ?? 'Triage permintaan.',
            'context' => ['priority' => $priority->value, 'category' => $request->category],
        ]);

        return $request->refresh();
    }

    /**
     * Tugaskan teknisi dan buat work order.
     *
     * Aset langsung berstatus DALAM PERAWATAN pada saat ini: selama dikerjakan,
     * ia tidak boleh dipinjam atau dipindahkan — kata "sedang diperbaiki" harus
     * terlihat oleh modul lain, bukan hanya oleh teknisi.
     */
    public function assign(User $actor, MaintenanceRequest $request, int $technicianId, array $data = []): MaintenanceWorkOrder
    {
        // Permintaan yang belum ditriage ditriage lebih dulu di dalam alur yang
        // sama. Sekolah sering langsung menugaskan teknisi tanpa tahap terpisah,
        // dan menolak langkah itu hanya membuat orang mencari jalan pintas;
        // dengan cara ini transisinya tetap tercatat dan tervalidasi.
        if ($this->requestStatus($request) === MaintenanceRequestStatus::NEW->value) {
            $request = $this->triage($actor, $request, array_intersect_key($data, array_flip(['priority', 'category', 'notes'])));
        }

        MaintenanceStateMachine::assert($this->requestStatus($request), MaintenanceRequestStatus::ASSIGNED->value, 'permintaan');

        return DB::transaction(function () use ($actor, $request, $technicianId, $data): MaintenanceWorkOrder {
            $priority = isset($data['priority']) ? MaintenancePriority::from((string) $data['priority']) : $this->priorityOf($request);

            $workOrder = MaintenanceWorkOrder::query()->create([
                'work_order_number' => $this->numbers->next('work_order', ['tahun' => now()->year]),
                'request_id' => $request->id,
                'asset_id' => $request->asset_id,
                'facility_id' => $request->facility_id,
                'type' => $data['type'] ?? WorkOrderType::CORRECTIVE->value,
                'status' => WorkOrderStatus::ASSIGNED->value,
                'priority' => $priority->value,
                'title' => $data['title'] ?? $request->title,
                'description' => $data['description'] ?? $request->description,
                'assigned_to' => $technicianId,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'due_at' => now()->addHours($priority->workOrderHours()),
                'contract_id' => $data['contract_id'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
            ]);

            $request->forceFill([
                'status' => MaintenanceRequestStatus::ASSIGNED->value,
                'assigned_to' => $technicianId,
                'work_order_id' => $workOrder->id,
                'priority' => $priority->value,
            ])->save();

            if ($request->asset_id !== null) {
                $asset = Asset::query()->find($request->asset_id);

                if ($asset !== null && ! AssetStateMachine::isFrozen($this->assetStatus($asset))) {
                    app(AssetRegisterService::class)->changeStatus(
                        $actor, $asset, AssetStatus::IN_MAINTENANCE,
                        'Work order '.$workOrder->work_order_number.' dibuka.',
                    );
                }
            }

            $this->log($workOrder, 'ASSIGNED', 'Ditugaskan kepada pengguna #'.$technicianId.'.', $actor);

            $this->notifications->notify(
                [$technicianId],
                ['MAINTENANCE_MANAGER'],
                'MAINTENANCE_ASSIGNED',
                'Work order baru: '.$workOrder->work_order_number,
                sprintf('Tenggat %s — prioritas %s.', $workOrder->due_at?->toDateTimeString(), $priority->value),
                $workOrder,
            );

            $this->audit->record(AuditAction::MAINTENANCE_ASSIGNED, $workOrder, $actor, [
                'status_after' => $workOrder->status,
                'reason' => 'Penugasan teknisi.',
                'context' => ['technician_id' => $technicianId, 'due_at' => $workOrder->due_at?->toDateTimeString()],
            ]);

            return $workOrder->refresh();
        });
    }

    public function start(User $actor, MaintenanceWorkOrder $workOrder, ?string $diagnosis = null): MaintenanceWorkOrder
    {
        WorkOrderStateMachine::assert($this->woStatus($workOrder), WorkOrderStatus::IN_PROGRESS->value, 'work order');

        $workOrder->forceFill([
            'status' => WorkOrderStatus::IN_PROGRESS->value,
            'started_at' => $workOrder->started_at ?? now(),
            'diagnosis' => $diagnosis ?? $workOrder->diagnosis,
        ])->save();

        if ($workOrder->request !== null) {
            $request = $workOrder->request;

            if (MaintenanceStateMachine::can($this->requestStatus($request), MaintenanceRequestStatus::IN_PROGRESS->value)) {
                $request->forceFill(['status' => MaintenanceRequestStatus::IN_PROGRESS->value])->save();
            }
        }

        $this->log($workOrder, 'STARTED', 'Pengerjaan dimulai.', $actor);

        return $workOrder->refresh();
    }

    /** Minta suku cadang dari Persediaan (stok tetap milik Persediaan). */
    public function requestParts(User $actor, MaintenanceWorkOrder $workOrder, array $items): array
    {
        $issued = [];
        $shortage = false;

        foreach ($items as $item) {
            $result = $this->inventory->issue([
                'code' => $item['code'],
                'quantity' => $item['quantity'] ?? 1,
                'reference' => $workOrder->work_order_number,
                'work_order_id' => $workOrder->id,
                'requested_by' => $actor->id,
            ]);

            if (($result['status'] ?? 'PENDING') !== 'ISSUED') {
                $shortage = true;
                $this->log($workOrder, 'PARTS_SHORTAGE', 'Persediaan belum mencukupi untuk '.$item['code'].'.', $actor, ['result' => $result]);

                continue;
            }

            $issued[] = $result;
            $workOrder->forceFill([
                'parts_cost' => bcadd((string) $workOrder->parts_cost, (string) ($result['total_cost'] ?? '0'), 2),
            ])->save();

            $this->log($workOrder, 'PARTS_ISSUED', 'Suku cadang dikeluarkan: '.$item['code'].'.', $actor, ['result' => $result]);
        }

        if ($shortage) {
            $workOrder->forceFill(['status' => WorkOrderStatus::ON_HOLD->value])->save();

            if ($workOrder->request !== null) {
                $workOrder->request->forceFill(['status' => MaintenanceRequestStatus::WAITING_PARTS->value])->save();
            }
        }

        return ['issued' => $issued, 'shortage' => $shortage, 'work_order' => $workOrder->refresh()];
    }

    /** Pengerjaan oleh vendor luar: biaya jasa dicatat terpisah dari suku cadang. */
    public function recordExternalService(User $actor, MaintenanceWorkOrder $workOrder, array $data): MaintenanceWorkOrder
    {
        $workOrder->forceFill([
            'external_cost' => bcadd((string) $workOrder->external_cost, (string) ($data['cost'] ?? '0'), 2),
            'vendor_id' => $data['vendor_id'] ?? $workOrder->vendor_id,
            'status' => WorkOrderStatus::IN_PROGRESS->value,
        ])->save();

        if ($workOrder->request !== null && $this->requestStatus($workOrder->request) === MaintenanceRequestStatus::WAITING_VENDOR->value) {
            $workOrder->request->forceFill(['status' => MaintenanceRequestStatus::IN_PROGRESS->value])->save();
        }

        $this->log($workOrder, 'EXTERNAL_SERVICE', 'Jasa luar dicatat: '.($data['description'] ?? 'servis vendor').'.', $actor, $data);

        return $workOrder->refresh();
    }

    /**
     * Selesaikan work order: biaya dijumlahkan, waktu henti dihitung, aset kembali aktif.
     */
    public function complete(User $actor, MaintenanceWorkOrder $workOrder, array $data = []): MaintenanceWorkOrder
    {
        WorkOrderStateMachine::assert($this->woStatus($workOrder), WorkOrderStatus::COMPLETED->value, 'work order');

        return DB::transaction(function () use ($actor, $workOrder, $data): MaintenanceWorkOrder {
            $costs = [
                'labor_cost' => (string) ($data['labor_cost'] ?? $workOrder->labor_cost),
                'parts_cost' => (string) ($data['parts_cost'] ?? $workOrder->parts_cost),
                'external_cost' => (string) ($data['external_cost'] ?? $workOrder->external_cost),
                'transport_cost' => (string) ($data['transport_cost'] ?? $workOrder->transport_cost),
                'other_cost' => (string) ($data['other_cost'] ?? $workOrder->other_cost),
            ];

            $total = '0.00';

            foreach ($costs as $cost) {
                $total = bcadd($total, $cost, 2);
            }

            $downtime = $workOrder->started_at !== null
                ? (int) $workOrder->started_at->diffInMinutes(now())
                : (int) $workOrder->downtime_minutes;

            $workOrder->forceFill($costs + [
                'total_cost' => $total,
                'downtime_minutes' => $downtime,
                'status' => WorkOrderStatus::COMPLETED->value,
                'completed_at' => now(),
                'repair_plan' => $data['repair_plan'] ?? $workOrder->repair_plan,
                'diagnosis' => $data['diagnosis'] ?? $workOrder->diagnosis,
                'notes' => $data['notes'] ?? $workOrder->notes,
            ])->save();

            if ($workOrder->request !== null) {
                $workOrder->request->forceFill([
                    'status' => MaintenanceRequestStatus::COMPLETED->value,
                    'completed_at' => now(),
                ])->save();
            }

            if ($workOrder->asset_id !== null) {
                $asset = Asset::query()->find($workOrder->asset_id);

                if ($asset !== null) {
                    app(AssetRegisterService::class)->changeStatus(
                        $actor, $asset, AssetStatus::ACTIVE,
                        'Work order '.$workOrder->work_order_number.' selesai.',
                    );

                    $asset->forceFill([
                        'last_maintenance_at' => now(),
                        'maintenance_count' => (int) $asset->maintenance_count + 1,
                        'downtime_minutes' => (int) $asset->downtime_minutes + $downtime,
                        'next_maintenance_at' => $data['next_maintenance_at'] ?? $asset->next_maintenance_at,
                    ])->save();
                }
            }

            $this->log($workOrder, 'COMPLETED', 'Work order selesai dengan total biaya '.$total.'.', $actor, $costs);

            // Biaya pemeliharaan dicatatkan ke Keuangan (atau masuk outbox bila
            // modul Keuangan belum terpasang) — bukan disimpan sebagai angka mati.
            $result = $this->finance->recordMaintenanceCost([
                'work_order_id' => $workOrder->id,
                'work_order_number' => $workOrder->work_order_number,
                'asset_id' => $workOrder->asset_id,
                'facility_id' => $workOrder->facility_id,
                'total_cost' => $total,
                'costs' => $costs,
                'vendor_id' => $workOrder->vendor_id,
                'actor_id' => $actor->id,
            ]);

            $workOrder->forceFill([
                'finance_status' => $result['status'] ?? 'PENDING',
                'finance_reference' => $result['reference'] ?? null,
            ])->save();

            $this->audit->record(AuditAction::MAINTENANCE_COMPLETED, $workOrder, $actor, [
                'status_before' => WorkOrderStatus::IN_PROGRESS->value,
                'status_after' => WorkOrderStatus::COMPLETED->value,
                'reason' => 'Pekerjaan selesai.',
                'context' => ['total_cost' => $total, 'downtime_minutes' => $downtime],
            ]);

            return $workOrder->refresh();
        });
    }

    /** Verifikasi hasil: permintaan ditutup hanya setelah diverifikasi. */
    public function verify(User $actor, MaintenanceWorkOrder $workOrder, array $data = []): MaintenanceWorkOrder
    {
        WorkOrderStateMachine::assert($this->woStatus($workOrder), WorkOrderStatus::VERIFIED->value, 'work order');

        $workOrder->forceFill([
            'status' => WorkOrderStatus::VERIFIED->value,
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ])->save();

        if ($workOrder->request !== null) {
            $request = $workOrder->request;

            if (MaintenanceStateMachine::can($this->requestStatus($request), MaintenanceRequestStatus::VERIFIED->value)) {
                $request->forceFill([
                    'status' => MaintenanceRequestStatus::VERIFIED->value,
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                ])->save();
            }

            $request->forceFill([
                'status' => MaintenanceRequestStatus::CLOSED->value,
                'closed_at' => now(),
            ])->save();
        }

        $this->audit->record(AuditAction::MAINTENANCE_COMPLETED, $workOrder, $actor, [
            'status_before' => WorkOrderStatus::COMPLETED->value,
            'status_after' => WorkOrderStatus::VERIFIED->value,
            'reason' => $data['notes'] ?? 'Hasil pekerjaan diverifikasi.',
        ]);

        return $workOrder->refresh();
    }

    public function cancel(User $actor, MaintenanceWorkOrder $workOrder, string $reason): MaintenanceWorkOrder
    {
        WorkOrderStateMachine::assert($this->woStatus($workOrder), WorkOrderStatus::CANCELLED->value, 'work order');

        $workOrder->forceFill(['status' => WorkOrderStatus::CANCELLED->value, 'notes' => $reason])->save();

        if ($workOrder->request !== null) {
            $workOrder->request->forceFill(['status' => MaintenanceRequestStatus::CANCELLED->value, 'notes' => $reason])->save();
        }

        if ($workOrder->asset_id !== null) {
            $asset = Asset::query()->find($workOrder->asset_id);

            if ($asset !== null) {
                app(AssetRegisterService::class)->changeStatus($actor, $asset, AssetStatus::ACTIVE, 'Work order dibatalkan: '.$reason);
            }
        }

        $this->audit->record(AuditAction::MAINTENANCE_CANCELLED, $workOrder, $actor, [
            'status_after' => WorkOrderStatus::CANCELLED->value, 'reason' => $reason,
        ]);

        return $workOrder->refresh();
    }

    public function addTask(User $actor, MaintenanceWorkOrder $workOrder, array $data): \App\Modules\Asset\Models\MaintenanceTask
    {
        return $workOrder->tasks()->create([
            'sequence' => (int) $workOrder->tasks()->max('sequence') + 1,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => TaskStatus::PENDING->value,
        ]);
    }

    public function completeTask(User $actor, MaintenanceWorkOrder $workOrder, int $taskId, array $data = []): \App\Modules\Asset\Models\MaintenanceTask
    {
        $task = $workOrder->tasks()->findOrFail($taskId);

        $task->forceFill([
            'status' => TaskStatus::DONE->value,
            'completed_by' => $actor->id,
            'completed_at' => now(),
            'notes' => $data['notes'] ?? $task->notes,
        ])->save();

        return $task;
    }

    /** Keterlambatan work order: dinaikkan prioritasnya dan diberitakan. */
    public function escalateOverdue(?Carbon $now = null): array
    {
        $now ??= now();

        $overdue = MaintenanceWorkOrder::query()
            ->whereIn('status', [WorkOrderStatus::OPEN->value, WorkOrderStatus::ASSIGNED->value, WorkOrderStatus::IN_PROGRESS->value, WorkOrderStatus::ON_HOLD->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->get();

        $escalated = [];

        foreach ($overdue as $workOrder) {
            $priority = $this->priorityOf($workOrder);

            $bumped = match ($priority) {
                MaintenancePriority::LOW => MaintenancePriority::NORMAL,
                MaintenancePriority::NORMAL => MaintenancePriority::HIGH,
                MaintenancePriority::HIGH, MaintenancePriority::URGENT => MaintenancePriority::CRITICAL,
                MaintenancePriority::CRITICAL => MaintenancePriority::CRITICAL,
            };

            $workOrder->forceFill(['priority' => $bumped->value])->save();

            $this->log($workOrder, 'ESCALATED', 'Melewati tenggat; prioritas dinaikkan ke '.$bumped->value.'.', null);

            $this->notifications->notify(
                array_values(array_filter([$workOrder->assigned_to])),
                ['MAINTENANCE_MANAGER'],
                'WORK_ORDER_OVERDUE',
                'Work order melewati tenggat: '.$workOrder->work_order_number,
                'Tenggat '.$workOrder->due_at?->toDateTimeString().' terlewat.',
                $workOrder,
            );

            $this->audit->record(AuditAction::MAINTENANCE_ESCALATED, $workOrder, null, [
                'status_after' => $this->woStatus($workOrder),
                'reason' => 'Melewati tenggat '.$workOrder->due_at?->toDateTimeString().'.',
                'context' => ['from_priority' => $priority->value, 'to_priority' => $bumped->value],
            ]);

            $escalated[] = $workOrder->work_order_number;
        }

        return $escalated;
    }

    public function log(MaintenanceWorkOrder $workOrder, string $type, string $message, ?User $actor = null, array $meta = []): MaintenanceLog
    {
        return $workOrder->logs()->create([
            'log_type' => $type,
            'message' => $message,
            'meta' => $meta ?: null,
            'logged_by' => $actor?->id,
        ]);
    }

    protected function resolvePriority(?string $requested, ?Asset $asset): MaintenancePriority
    {
        if ($requested !== null) {
            return MaintenancePriority::from($requested);
        }

        $criticality = $asset?->criticality;

        return $criticality instanceof AssetCriticality
            ? $criticality->defaultPriority()
            : MaintenancePriority::NORMAL;
    }

    protected function priorityOf(object $subject): MaintenancePriority
    {
        $value = $subject->priority ?? MaintenancePriority::NORMAL->value;

        return $value instanceof MaintenancePriority ? $value : MaintenancePriority::from((string) $value);
    }

    protected function requestStatus(MaintenanceRequest $request): string
    {
        return $request->status instanceof MaintenanceRequestStatus ? $request->status->value : (string) $request->status;
    }

    protected function woStatus(MaintenanceWorkOrder $workOrder): string
    {
        return $workOrder->status instanceof WorkOrderStatus ? $workOrder->status->value : (string) $workOrder->status;
    }

    protected function assetStatus(Asset $asset): string
    {
        return $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status;
    }
}
