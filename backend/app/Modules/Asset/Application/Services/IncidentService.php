<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Contracts\FinanceProviderInterface;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\DamageSeverity;
use App\Modules\Asset\Domain\Enums\IncidentStatus;
use App\Modules\Asset\Domain\Enums\IncidentType;
use App\Modules\Asset\Domain\Enums\LostResolution;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Domain\StateMachines\AssetStateMachine;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetIncident;
use App\Modules\Asset\Models\Facility;
use Illuminate\Support\Facades\DB;

/**
 * Insiden aset: rusak, hilang, dicuri, kecelakaan, penyalahgunaan.
 *
 * Aturan yang paling penting di sini: **aset hilang tidak pernah dihapus**. Ia
 * berstatus HILANG, diselidiki, lalu diselesaikan (ditemukan, diganti, atau
 * dihapusbukukan lewat jalur penghapusan). Menghapus barisnya akan menghilangkan
 * pertanyaan yang justru harus dijawab: ke mana barang itu pergi dan siapa yang
 * bertanggung jawab.
 */
class IncidentService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
        private readonly FinanceProviderInterface $finance,
    ) {}

    public function report(User $actor, array $data): AssetIncident
    {
        $incidentType = IncidentType::from((string) ($data['incident_type'] ?? IncidentType::DAMAGE->value));

        $asset = isset($data['asset_id']) ? Asset::query()->find($data['asset_id']) : null;
        $facility = isset($data['facility_id']) ? Facility::query()->find($data['facility_id']) : null;

        if ($asset === null && $facility === null) {
            throw new AssetValidationException(['asset_id' => ['Insiden harus menunjuk aset atau fasilitas.']]);
        }

        return DB::transaction(function () use ($actor, $data, $incidentType, $asset, $facility): AssetIncident {
            $incident = AssetIncident::query()->create([
                'incident_number' => $this->numbers->next('work_order', ['tahun' => now()->year]),
                'asset_id' => $asset?->id,
                'facility_id' => $facility?->id,
                'incident_type' => $incidentType->value,
                'status' => IncidentStatus::REPORTED->value,
                'severity' => $data['severity'] ?? DamageSeverity::MODERATE->value,
                'occurred_at' => $data['occurred_at'] ?? now(),
                'reported_by' => $actor->id,
                'description' => $data['description'],
                'document_reference' => $data['document_reference'] ?? null,
            ]);

            if ($asset !== null && in_array($incidentType, [IncidentType::LOSS, IncidentType::THEFT], true)) {
                $this->markLost($actor, $asset, $incident);
            }

            $this->notifications->notify(
                [],
                ['ASSET_ADMIN', 'ASSET_OFFICER'],
                'ASSET_INCIDENT',
                'Insiden aset: '.$incidentType->value,
                sprintf('%s — %s', $incident->incident_number, $incident->description),
                $incident,
            );

            $this->audit->record(AuditAction::INCIDENT_CREATED, $incident, $actor, [
                'status_after' => $incident->status,
                'reason' => $incident->description,
                'context' => ['type' => $incidentType->value],
            ]);

            return $incident->refresh();
        });
    }

    /** Tandai aset hilang: status berubah, barisnya TETAP ada. */
    public function markLost(User $actor, Asset $asset, ?AssetIncident $incident = null): Asset
    {
        $status = $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status;

        if ($status === AssetStatus::DISPOSED->value || $status === AssetStatus::RETIRED->value) {
            throw new AssetDomainException('Aset yang sudah dihapusbukukan tidak dapat ditandai hilang.', 409);
        }

        AssetStateMachine::assert($status, AssetStatus::LOST->value, 'aset');

        $asset->forceFill([
            'status' => AssetStatus::LOST->value,
            'version' => $asset->version + 1,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::ASSET_UPDATED, $asset, $actor, [
            'status_before' => $status,
            'status_after' => AssetStatus::LOST->value,
            'reason' => $incident !== null
                ? 'Insiden '.$incident->incident_number.' ('.$incident->incident_type->value.').'
                : 'Aset dinyatakan hilang.',
        ]);

        return $asset->refresh();
    }

    public function investigate(User $actor, AssetIncident $incident, array $data): AssetIncident
    {
        $incident->forceFill([
            'status' => IncidentStatus::UNDER_INVESTIGATION->value,
            'investigation_notes' => $data['investigation_notes'] ?? $incident->investigation_notes,
            'responsible_party' => $data['responsible_party'] ?? $incident->responsible_party,
        ])->save();

        $this->audit->record(AuditAction::INCIDENT_CREATED, $incident, $actor, [
            'status_before' => IncidentStatus::REPORTED->value,
            'status_after' => IncidentStatus::UNDER_INVESTIGATION->value,
            'reason' => $data['investigation_notes'] ?? 'Investigasi dimulai.',
        ]);

        return $incident->refresh();
    }

    /**
     * Selesaikan insiden.
     *
     * Setiap kemungkinan penyelesaian punya akibatnya sendiri:
     *  - DITEMUKAN → aset kembali aktif (menunggu inspeksi, bukan langsung dipakai);
     *  - DIGANTI → aset lama menunggu penghapusan, aset pengganti dicatat terpisah;
     *  - DIHAPUSBUKUKAN → permintaan penghapusan dibuat (bukan penghapusan langsung);
     *  - NILAI DIPULIHKAN → dicatatkan ke Keuangan.
     */
    public function resolve(User $actor, AssetIncident $incident, LostResolution $resolution, array $data = []): AssetIncident
    {
        $asset = $incident->asset_id !== null ? Asset::query()->find($incident->asset_id) : null;

        return DB::transaction(function () use ($actor, $incident, $resolution, $data, $asset): AssetIncident {
            $result = null;

            if ($asset !== null) {
                $result = match ($resolution) {
                    LostResolution::FOUND => $this->resolveFound($actor, $asset, $incident, $data),
                    LostResolution::WRITTEN_OFF => $this->resolveWrittenOff($actor, $asset, $incident, $data),
                    LostResolution::REPLACED => $this->resolveReplaced($actor, $asset, $incident, $data),
                    LostResolution::RECOVERED_VALUE => $this->resolveRecoveredValue($actor, $asset, $incident, $data),
                    LostResolution::OTHER => null,
                };
            }

            $incident->forceFill([
                'status' => IncidentStatus::RESOLVED->value,
                'resolution_type' => $resolution->value,
                'resolution' => $data['resolution'] ?? $resolution->value,
                'recovered_value' => $data['recovered_value'] ?? null,
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
                'document_reference' => $data['document_reference'] ?? $incident->document_reference,
            ])->save();

            $this->audit->record(AuditAction::INCIDENT_RESOLVED, $incident, $actor, [
                'status_before' => IncidentStatus::UNDER_INVESTIGATION->value,
                'status_after' => IncidentStatus::RESOLVED->value,
                'reason' => $data['resolution'] ?? 'Insiden diselesaikan: '.$resolution->value,
                'context' => ['resolution' => $resolution->value] + (array) $result,
            ]);

            return $incident->refresh();
        });
    }

    /** Ditemukan: aset menunggu inspeksi sebelum kembali dipakai. */
    protected function resolveFound(User $actor, Asset $asset, AssetIncident $incident, array $data): array
    {
        $inspection = app(InspectionService::class)->schedule($actor, [
            'asset_id' => $asset->id,
            'inspection_type' => 'ROUTINE',
            'scheduled_at' => $data['inspection_at'] ?? now()->addDay(),
            'notes' => 'Inspeksi setelah aset ditemukan ('.$incident->incident_number.').',
        ]);

        return ['inspection_id' => $inspection->id];
    }

    /** Dihapusbukukan: dibuatkan permintaan penghapusan — bukan dihapus di sini. */
    protected function resolveWrittenOff(User $actor, Asset $asset, AssetIncident $incident, array $data): array
    {
        $request = app(AssetDisposalService::class)->submit($actor, [
            'asset_id' => $asset->id,
            'reason' => 'LOST',
            'method' => 'WRITE_OFF',
            'description' => 'Aset hilang, tidak ditemukan: '.$incident->incident_number.'.',
            'estimated_value' => '0.00',
        ]);

        return ['disposal_request_id' => $request->id];
    }

    protected function resolveReplaced(User $actor, Asset $asset, AssetIncident $incident, array $data): array
    {
        if (isset($data['replacement_asset_id'])) {
            $replacement = Asset::query()->find($data['replacement_asset_id']);

            return ['replacement_asset_id' => $replacement?->id];
        }

        $replacement = app(AssetReplacementService::class)->recommendFor($asset);

        return ['replacement_recommendation' => $replacement['recommendation']->value ?? null];
    }

    /** Nilai yang berhasil dipulihkan dicatatkan ke Keuangan. */
    protected function resolveRecoveredValue(User $actor, Asset $asset, AssetIncident $incident, array $data): array
    {
        $result = $this->finance->revalue([
            'asset_id' => $asset->id,
            'valuation_type' => 'IMPAIRMENT',
            'amount' => (string) ($data['recovered_value'] ?? '0'),
            'reason' => 'Nilai dipulihkan dari insiden '.$incident->incident_number.'.',
            'effective_date' => now()->toDateString(),
            'actor_id' => $actor->id,
        ]);

        return ['finance_status' => $result['status'] ?? 'PENDING'];
    }
}
