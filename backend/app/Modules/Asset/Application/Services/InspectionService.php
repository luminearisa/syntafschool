<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Enums\AssetCondition;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\InspectionItemResult;
use App\Modules\Asset\Domain\Enums\InspectionStatus;
use App\Modules\Asset\Domain\Enums\InspectionType;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetInspection;
use App\Modules\Asset\Models\AssetInspectionTemplate;
use App\Modules\Asset\Models\Facility;

/**
 * Inspeksi dengan butir yang dapat dikonfigurasi.
 *
 * Hasil inspeksi bukan sekadar "lulus/gagal": butir yang gagal disimpan satu per
 * satu, dan bila menuntut tindak lanjut, modul ini yang membuat permintaan
 * pemeliharaannya — sehingga temuan inspeksi tidak berhenti sebagai berkas.
 */
class InspectionService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
    ) {}

    public function createTemplate(User $actor, array $data): AssetInspectionTemplate
    {
        return AssetInspectionTemplate::query()->create([
            'category_id' => $data['category_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'inspection_type' => $data['inspection_type'] ?? InspectionType::ROUTINE->value,
            'checklist' => $data['checklist'],
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /** Jadwalkan inspeksi (boleh untuk aset maupun fasilitas). */
    public function schedule(User $actor, array $data): AssetInspection
    {
        $asset = isset($data['asset_id']) ? Asset::query()->find($data['asset_id']) : null;
        $facility = isset($data['facility_id']) ? Facility::query()->find($data['facility_id']) : null;

        if ($asset === null && $facility === null) {
            throw new AssetValidationException(['asset_id' => ['Inspeksi harus menunjuk aset atau fasilitas.']]);
        }

        return AssetInspection::query()->create([
            'inspection_number' => $this->numbers->next('work_order', ['tahun' => now()->year]),
            'asset_id' => $asset?->id,
            'facility_id' => $facility?->id,
            'template_id' => $data['template_id'] ?? null,
            'inspection_type' => $data['inspection_type'] ?? InspectionType::ROUTINE->value,
            'status' => InspectionStatus::SCHEDULED->value,
            'scheduled_at' => $data['scheduled_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Lakukan inspeksi beserta hasil per butir.
     *
     * @param list<array{code:string,label?:string,result:string,value?:string,notes?:string}> $results
     */
    public function perform(User $actor, AssetInspection $inspection, array $results, array $data = []): AssetInspection
    {
        if ($inspection->status === InspectionStatus::CANCELLED) {
            throw new AssetValidationException(['status' => ['Inspeksi yang dibatalkan tidak dapat diisi.']]);
        }

        $failed = [];
        $applicable = 0;

        foreach ($results as $result) {
            $value = InspectionItemResult::from((string) ($result['result'] ?? InspectionItemResult::PASS->value));

            if ($value !== InspectionItemResult::NOT_APPLICABLE) {
                $applicable++;
            }

            if ($value === InspectionItemResult::FAIL) {
                $failed[] = $result['label'] ?? $result['code'];
            }

            $inspection->results()->create([
                'code' => $result['code'],
                'label' => $result['label'] ?? $result['code'],
                'result' => $value->value,
                'value' => $result['value'] ?? null,
                'notes' => $result['notes'] ?? null,
            ]);
        }

        $condition = isset($data['condition'])
            ? AssetCondition::from((string) $data['condition'])
            : ($failed === [] ? AssetCondition::GOOD : AssetCondition::POOR);

        $summary = match (true) {
            $failed === [] && ($data['notes'] ?? null) === null => InspectionStatus::PASSED,
            $failed === [] => InspectionStatus::PASSED_WITH_NOTES,
            default => InspectionStatus::FAILED,
        };

        $needsFollowUp = $failed !== [] || (bool) ($data['follow_up_required'] ?? false);

        $inspection->forceFill([
            'status' => $summary->value,
            'performed_at' => now(),
            'performed_by' => $actor->id,
            'result_summary' => $summary->value,
            'score' => $applicable > 0 ? round(($applicable - count($failed)) / $applicable * 100, 2) : null,
            'findings' => $failed === [] ? ($data['findings'] ?? null) : implode('; ', $failed),
            'condition_found' => $condition->value,
            'follow_up_required' => $needsFollowUp,
            'document_reference' => $data['document_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ])->save();

        if ($inspection->asset_id !== null) {
            $asset = Asset::query()->find($inspection->asset_id);

            if ($asset !== null) {
                app(AssetRegisterService::class)->changeCondition($actor, $asset, $condition, 'Hasil inspeksi '.$inspection->inspection_number.'.');

                $asset->forceFill([
                    'last_inspection_at' => now(),
                    'next_inspection_at' => $data['next_inspection_at']
                        ?? ($asset->category?->inspection_frequency_days !== null
                            ? now()->addDays((int) $asset->category->inspection_frequency_days)
                            : $asset->next_inspection_at),
                ])->save();
            }
        }

        // Temuan yang menuntut tindak lanjut langsung menjadi permintaan
        // pemeliharaan — inspeksi tanpa akibat adalah formulir, bukan pengendalian.
        if ($needsFollowUp) {
            $request = app(MaintenanceService::class)->createRequest($actor, [
                'asset_id' => $inspection->asset_id,
                'facility_id' => $inspection->facility_id,
                'title' => 'Tindak lanjut inspeksi '.$inspection->inspection_number,
                'description' => $inspection->findings ?? 'Temuan inspeksi memerlukan tindak lanjut.',
                'priority' => $failed === [] ? 'NORMAL' : 'HIGH',
                'source' => 'INSPECTION',
                'location_id' => $inspection->asset?->location_id,
            ]);

            $inspection->forceFill(['work_order_id' => null])->save();

            $this->notifications->notify(
                [],
                ['MAINTENANCE_MANAGER'],
                'INSPECTION_FOLLOW_UP',
                'Tindak lanjut inspeksi: '.$inspection->inspection_number,
                'Permintaan pemeliharaan '.$request->request_number.' dibuat dari temuan inspeksi.',
                $inspection,
            );
        }

        $this->audit->record(AuditAction::ASSET_INSPECTED, $inspection, $actor, [
            'status_after' => $summary->value,
            'reason' => 'Inspeksi '.$inspection->inspection_type->value.' dilaksanakan.',
            'context' => [
                'failed_items' => $failed,
                'condition_found' => $condition->value,
                'follow_up' => $needsFollowUp,
            ],
        ]);

        return $inspection->refresh();
    }

    /** Inspeksi yang mendesak dilakukan (mis. akan jatuh tempo). */
    public function dueSoon(int $days = 7): array
    {
        $assets = Asset::query()
            ->whereNotNull('next_inspection_at')
            ->whereBetween('next_inspection_at', [now(), now()->addDays($days)])
            ->orderBy('next_inspection_at')
            ->get(['id', 'asset_code', 'name', 'next_inspection_at', 'location_id']);

        return $assets->map(fn (Asset $asset): array => [
            'asset_id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'next_inspection_at' => $asset->next_inspection_at?->toDateTimeString(),
        ])->all();
    }
}
