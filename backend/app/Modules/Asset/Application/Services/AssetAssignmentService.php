<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Contracts\WorkflowProviderInterface;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AssignmentStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Domain\StateMachines\AssetStateMachine;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetAssignment;
use App\Modules\Asset\Models\AssetCustodian;
use App\Modules\Asset\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Penugasan aset: permintaan → persetujuan → penetapan → pengakuan penerima.
 *
 * Aset baru benar-benar berpindah setelah penerima MENGAKUI — termasuk kondisi
 * dan kelengkapannya. Pengakuan itu yang menjadi titik pertanggungjawaban bila
 * kemudian barang rusak atau hilang.
 */
class AssetAssignmentService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetRegisterService $register,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
        private readonly WorkflowProviderInterface $workflow,
    ) {}

    /** @param list<int> $assetIds */
    public function request(User $actor, array $data, array $assetIds): AssetAssignment
    {
        if ($assetIds === []) {
            throw new AssetValidationException(['assets' => ['Pilih setidaknya satu aset.']]);
        }

        return DB::transaction(function () use ($actor, $data, $assetIds): AssetAssignment {
            $assignment = AssetAssignment::query()->create([
                'assignment_number' => $this->numbers->next('loan', ['tahun' => now()->year]),
                'type' => $data['type'] ?? 'INDIVIDUAL',
                'status' => AssignmentStatus::REQUESTED->value,
                'requested_by' => $actor->id,
                'unit_id' => $data['unit_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'custodian_id' => $data['custodian_id'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'start_date' => $data['start_date'] ?? now()->toDateString(),
                'end_date' => $data['end_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach (Asset::query()->whereIn('id', $assetIds)->get() as $asset) {
                $assignment->items()->create([
                    'asset_id' => $asset->id,
                    'condition_before' => $asset->condition instanceof \App\Modules\Asset\Domain\Enums\AssetCondition
                        ? $asset->condition->value
                        : (string) $asset->condition,
                    'accessories' => $data['accessories'] ?? null,
                ]);
            }

            $this->workflow->start([
                'subject_type' => $assignment->getMorphClass(),
                'subject_id' => $assignment->id,
                'type' => 'ASSET_ASSIGNMENT',
                'requested_by' => $actor->id,
            ]);

            $this->audit->record(AuditAction::ASSET_ASSIGNED, $assignment, $actor, [
                'status_after' => $assignment->status,
                'reason' => 'Permintaan penugasan aset.',
                'context' => ['assets' => $assetIds],
            ]);

            return $assignment->refresh();
        });
    }

    public function approve(User $actor, AssetAssignment $assignment): AssetAssignment
    {
        if ($assignment->status !== AssignmentStatus::REQUESTED) {
            throw new AssetDomainException('Hanya permintaan yang masih menunggu dapat disetujui.', 409);
        }

        $assignment->forceFill([
            'status' => AssignmentStatus::APPROVED->value,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        $this->workflow->decide([
            'subject_type' => $assignment->getMorphClass(),
            'subject_id' => $assignment->id,
            'decision' => 'APPROVED',
            'actor_id' => $actor->id,
        ]);

        $this->audit->record(AuditAction::ASSET_ASSIGNED, $assignment, $actor, [
            'status_before' => AssignmentStatus::REQUESTED->value,
            'status_after' => AssignmentStatus::APPROVED->value,
            'reason' => 'Penugasan disetujui.',
        ]);

        return $assignment->refresh();
    }

    /** Tetapkan aset: perpindahan sebenarnya terjadi di sini. */
    public function assign(User $actor, AssetAssignment $assignment): AssetAssignment
    {
        if (! in_array($assignment->status, [AssignmentStatus::APPROVED, AssignmentStatus::ACTIVE], true)) {
            throw new AssetDomainException('Penugasan harus disetujui sebelum asetnya ditetapkan.', 409);
        }

        return DB::transaction(function () use ($actor, $assignment): AssetAssignment {
            $custodian = $assignment->custodian_id !== null
                ? AssetCustodian::query()->find($assignment->custodian_id)
                : null;

            foreach ($assignment->items()->with('asset')->get() as $item) {
                $asset = $item->asset;

                if ($asset === null) {
                    continue;
                }

                if (AssetStateMachine::isFrozen($this->statusValue($asset))) {
                    throw new AssetDomainException(sprintf(
                        'Aset %s berstatus %s dan tidak dapat ditugaskan.',
                        $asset->asset_code, $this->statusValue($asset),
                    ), 409);
                }

                if ($asset->active_loan_id !== null) {
                    throw new AssetDomainException(sprintf('Aset %s sedang dipinjam; selesaikan pinjamannya lebih dahulu.', $asset->asset_code), 409);
                }

                $asset->forceFill([
                    'status' => AssetStatus::ASSIGNED->value,
                    'unit_id' => $assignment->unit_id ?? $asset->unit_id,
                    'location_id' => $assignment->location_id ?? $asset->location_id,
                    'custodian_id' => $custodian?->user_id ?? $asset->custodian_id,
                    'custodian_type' => $custodian?->custodian_type ?? $asset->custodian_type,
                    'custodian_label' => $custodian?->name ?? $asset->custodian_label,
                    'version' => $asset->version + 1,
                ])->save();

                if ($custodian !== null) {
                    $this->register->openCustody($actor, $asset, $custodian, $asset->location_id, 'Penugasan aset.');
                }
            }

            $assignment->forceFill([
                'status' => AssignmentStatus::ACTIVE->value,
                'document_reference' => $assignment->document_reference,
            ])->save();

            $this->audit->record(AuditAction::ASSET_ASSIGNED, $assignment, $actor, [
                'status_before' => AssignmentStatus::APPROVED->value,
                'status_after' => AssignmentStatus::ACTIVE->value,
                'reason' => 'Aset ditetapkan kepada pemegang.',
            ]);

            return $assignment->refresh();
        });
    }

    /** Pengakuan penerima: kondisi, kelengkapan, dan catatannya. */
    public function acknowledge(User $actor, AssetAssignment $assignment, array $data): AssetAssignment
    {
        if ($assignment->status !== AssignmentStatus::ACTIVE) {
            throw new AssetDomainException('Pengakuan hanya dapat diberikan atas penugasan yang sudah berjalan.', 409);
        }

        $assignment->forceFill([
            'status' => AssignmentStatus::ACKNOWLEDGED->value,
            'acknowledged_at' => now(),
            'acknowledgement_condition' => $data['condition'] ?? null,
            'acknowledgement_notes' => $data['notes'] ?? null,
        ])->save();

        foreach ($assignment->items as $item) {
            $item->forceFill([
                'condition_after' => $data['condition'] ?? null,
                'accessories' => $data['accessories'] ?? $item->accessories,
                'notes' => $data['item_notes'][$item->asset_id] ?? $item->notes,
            ])->save();
        }

        $assignment->items()->with('asset')->get()->each(function ($item) use ($actor, $data): void {
            if ($item->asset === null || ($data['condition'] ?? null) === null) {
                return;
            }

            app(AssetRegisterService::class)->changeCondition(
                $actor, $item->asset,
                \App\Modules\Asset\Domain\Enums\AssetCondition::from((string) $data['condition']),
                'Kondisi saat pengakuan penugasan '.$assignment->assignment_number.'.',
            );
        });

        $this->audit->record(AuditAction::ASSET_ASSIGNED, $assignment, $actor, [
            'status_before' => AssignmentStatus::ACTIVE->value,
            'status_after' => AssignmentStatus::ACKNOWLEDGED->value,
            'reason' => 'Penerima mengakui penugasan.',
            'context' => ['condition' => $data['condition'] ?? null],
        ]);

        return $assignment->refresh();
    }

    /** Selesaikan penugasan: aset kembali ke keadaan aktif tanpa pemegang baru. */
    public function complete(User $actor, AssetAssignment $assignment, ?string $reason = null): AssetAssignment
    {
        return DB::transaction(function () use ($actor, $assignment, $reason): AssetAssignment {
            foreach ($assignment->items()->with('asset')->get() as $item) {
                $asset = $item->asset;

                if ($asset === null) {
                    continue;
                }

                $asset->forceFill([
                    'status' => AssetStatus::ACTIVE->value,
                    'custodian_id' => null,
                    'custodian_label' => null,
                    'version' => $asset->version + 1,
                ])->save();

                $asset->custodianHistory()->whereNull('released_at')->update([
                    'released_at' => now(),
                    'released_by' => $actor->id,
                    'reason' => $reason ?? 'Penugasan selesai.',
                ]);
            }

            $assignment->forceFill(['status' => AssignmentStatus::COMPLETED->value])->save();

            $this->audit->record(AuditAction::ASSET_ASSIGNED, $assignment, $actor, [
                'status_before' => $assignment->status, 'status_after' => AssignmentStatus::COMPLETED->value,
                'reason' => $reason ?? 'Penugasan selesai.',
            ]);

            return $assignment->refresh();
        });
    }

    protected function statusValue(Asset $asset): string
    {
        return $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status;
    }
}
