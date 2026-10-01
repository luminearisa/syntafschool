<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Contracts\WorkflowProviderInterface;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\TransferStatus;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Domain\StateMachines\AssetStateMachine;
use App\Modules\Asset\Domain\StateMachines\TransferStateMachine;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCustodian;
use App\Modules\Asset\Models\AssetCustodianHistory;
use App\Modules\Asset\Models\AssetTransfer;
use App\Modules\Asset\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Pemindahan aset: permintaan → persetujuan → serah terima → konfirmasi penerima.
 *
 * Empat aturan yang ditegakkan di sini, semuanya berasal dari pengalaman nyata:
 *  - pemindahan WAJIB punya tujuan (tanpa tujuan, aset "hilang" dari catatan);
 *  - aset yang sedang diperbaiki atau sudah dihapusbukukan tidak dapat dipindahkan;
 *  - aset yang sedang DIPINJAM tidak dapat dipindahkan tanpa pemulangan lebih dulu;
 *  - perpindahan baru SELESAI setelah penerima mengonfirmasi, bukan setelah
 *    pengirim mengirim.
 */
class AssetTransferService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetRegisterService $register,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
        private readonly WorkflowProviderInterface $workflow,
    ) {}

    /** @param list<int> $assetIds */
    public function request(User $actor, array $data, array $assetIds): AssetTransfer
    {
        $toLocation = Location::query()->find($data['to_location_id'] ?? null);

        if ($toLocation === null) {
            throw new AssetValidationException(['to_location_id' => ['Tujuan pemindahan wajib diisi dan harus ada.']]);
        }

        if ($assetIds === []) {
            throw new AssetValidationException(['assets' => ['Pilih setidaknya satu aset.']]);
        }

        return DB::transaction(function () use ($actor, $data, $assetIds, $toLocation): AssetTransfer {
            $assets = Asset::query()->whereIn('id', $assetIds)->get();

            foreach ($assets as $asset) {
                $this->assertTransferable($asset, (int) $data['to_location_id']);
            }

            $first = $assets->first();

            $transfer = AssetTransfer::query()->create([
                'transfer_number' => $this->numbers->next('transfer', ['tahun' => now()->year]),
                'type' => $data['type'] ?? 'ROOM_TRANSFER',
                'status' => TransferStatus::REQUESTED->value,
                'requested_by' => $actor->id,
                'reason' => $data['reason'] ?? null,
                'from_location_id' => $data['from_location_id'] ?? $first?->location_id,
                'to_location_id' => $toLocation->id,
                'from_custodian_id' => $data['from_custodian_id'] ?? null,
                'to_custodian_id' => $data['to_custodian_id'] ?? null,
                'from_unit_id' => $data['from_unit_id'] ?? $first?->unit_id,
                'to_unit_id' => $data['to_unit_id'] ?? $toLocation->responsible_unit_id,
                'inspection_id' => $data['inspection_id'] ?? null,
                'document_reference' => $data['document_reference'] ?? null,
            ]);

            foreach ($assets as $asset) {
                $transfer->items()->create([
                    'asset_id' => $asset->id,
                    'condition_before' => $this->conditionValue($asset),
                ]);
            }

            $this->workflow->start([
                'subject_type' => $transfer->getMorphClass(),
                'subject_id' => $transfer->id,
                'type' => 'ASSET_TRANSFER',
                'requested_by' => $actor->id,
            ]);

            $this->audit->record(AuditAction::ASSET_TRANSFERRED, $transfer, $actor, [
                'status_after' => $transfer->status,
                'from' => $transfer->from_location_id ? Location::query()->find($transfer->from_location_id)?->name : null,
                'to' => $toLocation->name,
                'reason' => 'Permintaan pemindahan aset.',
                'context' => ['assets' => $assetIds],
            ]);

            return $transfer->refresh();
        });
    }

    public function approve(User $actor, AssetTransfer $transfer): AssetTransfer
    {
        TransferStateMachine::assert($this->statusValue($transfer), TransferStatus::APPROVED->value, 'pemindahan');

        $transfer->forceFill(['status' => TransferStatus::APPROVED->value, 'approved_by' => $actor->id, 'approved_at' => now()])->save();

        $this->workflow->decide([
            'subject_type' => $transfer->getMorphClass(),
            'subject_id' => $transfer->id,
            'decision' => 'APPROVED',
            'actor_id' => $actor->id,
        ]);

        $this->audit->record(AuditAction::ASSET_TRANSFERRED, $transfer, $actor, [
            'status_before' => TransferStatus::REQUESTED->value,
            'status_after' => TransferStatus::APPROVED->value,
            'reason' => 'Pemindahan disetujui.',
        ]);

        return $transfer->refresh();
    }

    /** Serah terima dari pengirim (kondisi diperiksa sebelum barang berpindah). */
    public function handover(User $actor, AssetTransfer $transfer, array $data = []): AssetTransfer
    {
        TransferStateMachine::assert($this->statusValue($transfer), TransferStatus::HANDED_OVER->value, 'pemindahan');

        return DB::transaction(function () use ($actor, $transfer, $data): AssetTransfer {
            foreach ($transfer->items as $item) {
                $condition = $data['conditions'][$item->asset_id] ?? null;

                $item->forceFill([
                    'condition_before' => $condition ?? $item->condition_before,
                    'notes' => $data['notes'][$item->asset_id] ?? $item->notes,
                ])->save();

                $asset = $item->asset;

                if ($asset !== null && $condition !== null) {
                    $asset->forceFill(['condition' => $condition])->save();
                }
            }

            $transfer->forceFill([
                'status' => TransferStatus::HANDED_OVER->value,
                'handed_over_at' => now(),
                'handover_by' => $actor->id,
                'document_reference' => $data['document_reference'] ?? $transfer->document_reference,
            ])->save();

            $this->audit->record(AuditAction::ASSET_TRANSFERRED, $transfer, $actor, [
                'status_before' => TransferStatus::APPROVED->value,
                'status_after' => TransferStatus::HANDED_OVER->value,
                'reason' => 'Serah terima dilakukan.',
            ]);

            return $transfer->refresh();
        });
    }

    /**
     * Konfirmasi penerimaan: di titik ini lokasi dan pemegang BENAR-BENAR berubah.
     *
     * Yang penting: yang mengonfirmasi tidak boleh orang yang menyerahkan pada
     * langkah sebelumnya untuk perpindahan antar-unit — kalau tidak, serah
     * terima menjadi formalitas.
     */
    public function receive(User $actor, AssetTransfer $transfer, array $data = []): AssetTransfer
    {
        TransferStateMachine::assert($this->statusValue($transfer), TransferStatus::COMPLETED->value, 'pemindahan');

        if ($transfer->handover_by !== null && (int) $transfer->handover_by === (int) $actor->id && ! $actor->can('asset.manage')) {
            throw new AssetDomainException(
                'Konfirmasi penerimaan harus dilakukan oleh penerima, bukan oleh yang menyerahkan.',
                409,
            );
        }

        return DB::transaction(function () use ($actor, $transfer, $data): AssetTransfer {
            $toLocation = Location::query()->find($transfer->to_location_id);
            $toCustodian = $transfer->to_custodian_id !== null ? AssetCustodian::query()->find($transfer->to_custodian_id) : null;

            foreach ($transfer->items as $item) {
                $asset = $item->asset;

                if ($asset === null) {
                    continue;
                }

                $before = [
                    'location_id' => $asset->location_id,
                    'custodian_id' => $asset->custodian_id,
                    'unit_id' => $asset->unit_id,
                ];

                $asset->forceFill([
                    'location_id' => $transfer->to_location_id,
                    'unit_id' => $transfer->to_unit_id ?? $asset->unit_id,
                    'custodian_id' => $toCustodian?->user_id ?? null,
                    'custodian_type' => $toCustodian?->custodian_type ?? $asset->custodian_type,
                    'custodian_label' => $toCustodian?->name ?? null,
                    'status' => $toCustodian !== null ? AssetStatus::ASSIGNED->value : AssetStatus::ACTIVE->value,
                    'version' => $asset->version + 1,
                    'updated_by' => $actor->id,
                ])->save();

                $item->forceFill([
                    'condition_after' => $data['conditions'][$asset->id] ?? $this->conditionValue($asset),
                ])->save();

                // Riwayat pemegang: baris lama DITUTUP, baris baru DIBUKA.
                $open = $asset->custodianHistory()->whereNull('released_at')->latest('id')->first();

                if ($open !== null) {
                    $open->forceFill([
                        'released_at' => now(),
                        'released_by' => $actor->id,
                        'reason' => 'Dipindahkan melalui '.$transfer->transfer_number.'.',
                        'transfer_id' => $transfer->id,
                    ])->save();
                }

                if ($toCustodian !== null) {
                    $this->register->openCustody($actor, $asset, $toCustodian, $toLocation?->id, 'Penerimaan pemindahan '.$transfer->transfer_number, $transfer->id);
                }

                $this->audit->record(AuditAction::ASSET_TRANSFERRED, $asset, $actor, [
                    'before' => $before,
                    'after' => ['location_id' => $asset->location_id, 'custodian_id' => $asset->custodian_id, 'unit_id' => $asset->unit_id],
                    'from' => $transfer->from_location_id ? Location::query()->find($transfer->from_location_id)?->name : null,
                    'to' => $toLocation?->name,
                    'reason' => 'Penerimaan pemindahan '.$transfer->transfer_number.'.',
                ]);
            }

            $transfer->forceFill([
                'status' => TransferStatus::COMPLETED->value,
                'received_at' => now(),
                'received_by' => $actor->id,
                'recipient_condition' => $data['recipient_condition'] ?? null,
                'recipient_notes' => $data['recipient_notes'] ?? null,
            ])->save();

            $this->notifications->notify(
                [(int) $transfer->requested_by],
                ['ASSET_OFFICER'],
                'ASSET_TRANSFER_COMPLETED',
                'Pemindahan selesai: '.$transfer->transfer_number,
                'Aset telah diterima di '.($toLocation?->name ?? 'lokasi tujuan').'.',
                $transfer,
            );

            $this->audit->record(AuditAction::ASSET_TRANSFERRED, $transfer, $actor, [
                'status_before' => TransferStatus::HANDED_OVER->value,
                'status_after' => TransferStatus::COMPLETED->value,
                'reason' => 'Penerima mengonfirmasi penerimaan.',
            ]);

            return $transfer->refresh();
        });
    }

    public function cancel(User $actor, AssetTransfer $transfer, string $reason): AssetTransfer
    {
        TransferStateMachine::assert($this->statusValue($transfer), TransferStatus::CANCELLED->value, 'pemindahan');

        $transfer->forceFill(['status' => TransferStatus::CANCELLED->value, 'reason' => $reason])->save();

        $this->audit->record(AuditAction::ASSET_TRANSFERRED, $transfer, $actor, [
            'status_after' => TransferStatus::CANCELLED->value,
            'reason' => $reason,
        ]);

        return $transfer->refresh();
    }

    /** Aset yang dapat dipindahkan ke tujuan tertentu. */
    protected function assertTransferable(Asset $asset, int $toLocationId): void
    {
        // Status ASET (bukan status pemindahan) — dua tipe berbeda yang mudah tertukar.
        $status = $asset->status instanceof \App\Modules\Asset\Domain\Enums\AssetStatus
            ? $asset->status->value
            : (string) $asset->status;

        if (in_array($status, [AssetStatus::DISPOSED->value, AssetStatus::RETIRED->value], true)) {
            throw new AssetDomainException(sprintf('Aset %s sudah dihapusbukukan/diarsipkan dan tidak dapat dipindahkan.', $asset->asset_code), 409);
        }

        if ($status === AssetStatus::IN_MAINTENANCE->value) {
            throw new AssetDomainException(sprintf('Aset %s sedang dalam perbaikan; selesaikan work order-nya lebih dahulu.', $asset->asset_code), 409);
        }

        // Aturan §97.11: aset dengan pinjaman berjalan tidak boleh dipindahkan
        // tanpa proses khusus — di sini proses khusus itu adalah pemulangan.
        if ($asset->active_loan_id !== null) {
            throw new AssetDomainException(sprintf('Aset %s sedang dipinjam. Minta pemulangan sebelum memindahkannya.', $asset->asset_code), 409);
        }

        if ((int) $asset->location_id === $toLocationId) {
            throw new AssetValidationException(['to_location_id' => [sprintf('Aset %s sudah berada di lokasi tujuan.', $asset->asset_code)]]);
        }
    }

    protected function statusValue(AssetTransfer $transfer): string
    {
        return $transfer->status instanceof TransferStatus ? $transfer->status->value : (string) $transfer->status;
    }

    protected function conditionValue(Asset $asset): string
    {
        return $asset->condition instanceof \App\Modules\Asset\Domain\Enums\AssetCondition
            ? $asset->condition->value
            : (string) $asset->condition;
    }
}
