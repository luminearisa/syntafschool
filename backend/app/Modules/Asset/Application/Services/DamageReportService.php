<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Enums\AssetCondition;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\DamageReportStatus;
use App\Modules\Asset\Domain\Enums\DamageSeverity;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Domain\StateMachines\AssetStateMachine;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\DamageReport;
use App\Modules\Asset\Models\Facility;
use Illuminate\Support\Facades\DB;

/**
 * Laporan kerusakan: siapa pun yang melihat barang rusak dapat melaporkannya.
 *
 * Pelaporan sengaja dibuat terbuka (guru, siswa, pengasuh) karena kerusakan yang
 * tidak dilaporkan adalah kerusakan yang bertambah mahal. Yang dijaga adalah
 * langkah sesudahnya: verifikasi, inspeksi, dan (bila perlu) pemeliharaan.
 */
class DamageReportService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
    ) {}

    public function report(User $actor, array $data): DamageReport
    {
        $asset = isset($data['asset_id']) ? Asset::query()->find($data['asset_id']) : null;
        $facility = isset($data['facility_id']) ? Facility::query()->find($data['facility_id']) : null;

        if ($asset === null && $facility === null) {
            throw new AssetValidationException(['asset_id' => ['Laporan harus menunjuk aset atau fasilitas.']]);
        }

        $severity = DamageSeverity::from((string) ($data['severity'] ?? DamageSeverity::MODERATE->value));

        return DB::transaction(function () use ($actor, $data, $asset, $facility, $severity): DamageReport {
            $report = DamageReport::query()->create([
                'report_number' => $this->numbers->next('work_order', ['tahun' => now()->year]),
                'asset_id' => $asset?->id,
                'facility_id' => $facility?->id,
                'location_id' => $data['location_id'] ?? $asset?->location_id ?? $facility?->location_id,
                'reported_by' => $actor->id,
                'reported_at' => now(),
                'severity' => $severity->value,
                'status' => DamageReportStatus::REPORTED->value,
                'damage_type' => $data['damage_type'] ?? null,
                'description' => $data['description'],
                'photo_path' => $data['photo_path'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Kerusakan berat mengubah keadaan aset SEKARANG, bukan menunggu
            // verifikasi: barang yang rusak berat tidak boleh tetap "aktif" dan
            // dipinjam orang lain besok pagi.
            if ($asset !== null && $severity->rank() >= DamageSeverity::MAJOR->rank()) {
                $status = $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status;

                if (AssetStateMachine::can($status, AssetStatus::DAMAGED->value)) {
                    app(AssetRegisterService::class)->changeStatus($actor, $asset, AssetStatus::DAMAGED, 'Laporan kerusakan '.$report->report_number.'.');
                }

                app(AssetRegisterService::class)->changeCondition(
                    $actor, $asset,
                    $severity === DamageSeverity::TOTAL ? AssetCondition::UNUSABLE : AssetCondition::DAMAGED,
                    'Laporan kerusakan '.$report->report_number.'.',
                );
            }

            $this->notifications->notify(
                array_values(array_filter([$asset?->custodian_id, $actor->id])),
                ['ASSET_OFFICER', 'MAINTENANCE_MANAGER'],
                'DAMAGE_REPORTED',
                'Laporan kerusakan: '.($asset?->name ?? $facility?->name ?? 'fasilitas'),
                sprintf('Tingkat %s — %s', $severity->value, $report->report_number),
                $report,
            );

            $this->audit->record(AuditAction::DAMAGE_REPORTED, $report, $actor, [
                'status_after' => $report->status,
                'reason' => $report->description,
                'context' => ['severity' => $severity->value, 'damage_type' => $report->damage_type],
            ]);

            return $report->refresh();
        });
    }

    /** Verifikasi laporan: diterima (menuju perbaikan) atau ditolak dengan alasan. */
    public function verify(User $actor, DamageReport $report, bool $accepted = true, ?string $notes = null): DamageReport
    {
        $report->forceFill([
            'status' => $accepted ? DamageReportStatus::VERIFIED->value : DamageReportStatus::REJECTED->value,
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'notes' => $notes ?? $report->notes,
        ])->save();

        $this->audit->record(AuditAction::DAMAGE_REPORTED, $report, $actor, [
            'status_before' => DamageReportStatus::REPORTED->value,
            'status_after' => $report->status instanceof DamageReportStatus ? $report->status->value : (string) $report->status,
            'reason' => $notes ?? ($accepted ? 'Laporan diverifikasi.' : 'Laporan ditolak.'),
        ]);

        return $report->refresh();
    }

    /** Jadikan laporan sebagai permintaan pemeliharaan. */
    public function createMaintenance(User $actor, DamageReport $report, array $data = []): \App\Modules\Asset\Models\MaintenanceRequest
    {
        $severity = $report->severity instanceof DamageSeverity
            ? $report->severity
            : DamageSeverity::from((string) $report->severity);

        $request = app(MaintenanceService::class)->createRequest($actor, [
            'asset_id' => $report->asset_id,
            'facility_id' => $report->facility_id,
            'title' => $data['title'] ?? 'Perbaikan: '.($report->asset?->name ?? $report->facility?->name ?? 'aset'),
            'description' => $data['description'] ?? $report->description,
            'priority' => $data['priority'] ?? $severity->priority()->value,
            'source' => 'DAMAGE_REPORT',
            'damage_report_id' => $report->id,
        ]);

        $report->forceFill([
            'status' => DamageReportStatus::MAINTENANCE_CREATED->value,
            'maintenance_request_id' => $request->id,
        ])->save();

        return $request;
    }

    public function resolve(User $actor, DamageReport $report, string $resolution, array $data = []): DamageReport
    {
        $report->forceFill([
            'status' => DamageReportStatus::RESOLVED->value,
            'resolution' => $resolution,
            'resolved_at' => now(),
            'notes' => $data['notes'] ?? $report->notes,
        ])->save();

        $this->audit->record(AuditAction::DAMAGE_REPORTED, $report, $actor, [
            'status_after' => DamageReportStatus::RESOLVED->value,
            'reason' => $resolution,
        ]);

        return $report->refresh();
    }
}
