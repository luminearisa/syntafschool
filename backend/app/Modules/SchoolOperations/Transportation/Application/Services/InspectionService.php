<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\MaintenanceGatewayInterface;
use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\{
    PreTripInspection, PreTripInspectionItem, PostTripInspection, LostFoundItem, Trip, VehicleProfile
};
use Illuminate\Support\Facades\DB;

/**
 * Checklist pra-trip & pasca-trip (§69/§70).
 * Kegagalan item keselamatan kritis memblokir keberangkatan (§87 Rule 10).
 */
class InspectionService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly MaintenanceGatewayInterface $maintenance,
    ) {
    }

    public function checklistTemplate(): array
    {
        return (array) config('transportation.pre_trip_items', []);
    }

    public function preTrip(array $payload, ?string $idempotencyKey = null): array
    {
        $vehicle = VehicleProfile::query()->findOrFail((int) $payload['vehicle_id']);
        $trip = ! empty($payload['trip_id']) ? Trip::query()->find((int) $payload['trip_id']) : null;

        $result = $this->context->idempotent('PRETRIP', $idempotencyKey, function () use ($vehicle, $trip, $payload, $idempotencyKey): array {
            $items = $payload['items'] ?? $this->checklistTemplate();
            $odometer = isset($payload['odometer']) ? (float) $payload['odometer'] : null;

            $inspection = PreTripInspection::query()->create([
                'trip_id' => $trip?->id,
                'vehicle_id' => $vehicle->id,
                'driver_profile_id' => $payload['driver_profile_id'] ?? $trip?->driver_profile_id,
                'inspected_at' => $payload['inspected_at'] ?? now(),
                'result' => 'PASSED',
                'critical_failure' => false,
                'blocks_dispatch' => false,
                'odometer' => $odometer,
                'fuel_level' => $payload['fuel_level'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'signature_path' => $payload['signature_path'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'device_id' => $payload['device_id'] ?? null,
                'offline_sync' => (bool) ($payload['offline_sync'] ?? false),
            ]);

            $failed = 0;
            $criticalFailure = false;
            foreach ($items as $index => $item) {
                $code = strtoupper((string) ($item['code'] ?? $item['item_code'] ?? 'ITEM_'.($index + 1)));
                $defaults = collect($this->checklistTemplate())->firstWhere('code', $code) ?? [];
                $resultValue = strtoupper((string) ($item['result'] ?? 'OK'));
                $critical = (bool) ($item['critical'] ?? ($defaults['critical'] ?? false));
                $isFailure = in_array($resultValue, ['FAILED', 'ATTENTION'], true) && ($item['result'] ?? '') !== 'NA';

                if ($isFailure) {
                    $failed++;
                    $criticalFailure = $criticalFailure || $critical;
                }

                PreTripInspectionItem::query()->create([
                    'inspection_id' => $inspection->id,
                    'item_code' => $code,
                    'item_label' => $item['label'] ?? ($defaults['label'] ?? $code),
                    'category' => $item['category'] ?? ($defaults['category'] ?? 'VEHICLE'),
                    'mandatory' => (bool) ($item['mandatory'] ?? ($defaults['mandatory'] ?? true)),
                    'critical' => $critical,
                    'result' => $resultValue,
                    'notes' => $item['notes'] ?? null,
                    'photo_path' => $item['photo_path'] ?? null,
                ]);
            }

            $inspection->forceFill([
                'items_checked' => count($items),
                'items_failed' => $failed,
                'critical_failure' => $criticalFailure,
                'blocks_dispatch' => $criticalFailure && (bool) config('transportation.dispatch.block_on_critical_pretrip_failure', true),
                'result' => $criticalFailure ? 'FAILED' : ($failed > 0 ? 'CONDITIONAL' : 'PASSED'),
            ])->save();

            if ($odometer !== null && $trip) {
                $trip->forceFill(['start_odometer' => $odometer])->save();
            }

            $this->context->audit('PreTripInspectionRecorded', $inspection, [
                'vehicle_id' => $vehicle->id, 'result' => $inspection->result, 'failed' => $failed, 'critical' => $criticalFailure,
            ]);

            return [
                'inspection_id' => $inspection->id,
                'result' => $inspection->result,
                'items_checked' => count($items),
                'items_failed' => $failed,
                'critical_failure' => $criticalFailure,
                'blocks_dispatch' => (bool) $inspection->blocks_dispatch,
                'odometer' => $odometer,
            ];
        }, ['source' => 'PRETRIP', 'device_id' => $payload['device_id'] ?? null]);

        return $result;
    }

    public function postTrip(array $payload, ?string $idempotencyKey = null): array
    {
        $vehicle = VehicleProfile::query()->findOrFail((int) $payload['vehicle_id']);
        $trip = ! empty($payload['trip_id']) ? Trip::query()->find((int) $payload['trip_id']) : null;

        $result = $this->context->idempotent('POSTTRIP', $idempotencyKey, function () use ($vehicle, $trip, $payload, $idempotencyKey): array {
            $odometer = isset($payload['odometer']) ? (float) $payload['odometer'] : null;

            $inspection = PostTripInspection::query()->create([
                'trip_id' => $trip?->id,
                'vehicle_id' => $vehicle->id,
                'driver_profile_id' => $payload['driver_profile_id'] ?? $trip?->driver_profile_id,
                'inspected_at' => now(),
                'vehicle_condition' => strtoupper((string) ($payload['vehicle_condition'] ?? 'GOOD')),
                'odometer' => $odometer,
                'fuel_level' => $payload['fuel_level'] ?? null,
                'damage_found' => (bool) ($payload['damage_found'] ?? false),
                'damage_description' => $payload['damage_description'] ?? null,
                'lost_items_found' => (bool) ($payload['lost_items_found'] ?? false),
                'passenger_issues' => (bool) ($payload['passenger_issues'] ?? false),
                'passenger_issue_notes' => $payload['passenger_issue_notes'] ?? null,
                'incident_occurred' => (bool) ($payload['incident_occurred'] ?? false),
                'cleaning_required' => (bool) ($payload['cleaning_required'] ?? false),
                'maintenance_required' => (bool) ($payload['maintenance_required'] ?? ($payload['damage_found'] ?? false)),
                'notes' => $payload['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $maintenanceRequest = null;
            if ($inspection->maintenance_required) {
                $maintenanceRequest = $this->maintenance->createRequest([
                    'asset_id' => $vehicle->asset_id,
                    'title' => 'Temuan pasca-trip '.$vehicle->vehicle_code,
                    'description' => $inspection->damage_description ?? 'Temuan pasca-trip dilaporkan pengemudi.',
                    'status' => 'SUBMITTED',
                    'reference' => 'TMS-POST-'.now()->format('ymdHis'),
                ]);
                $inspection->forceFill(['maintenance_reference_id' => $maintenanceRequest['id'] ?? null])->save();
            }

            $lostItems = [];
            if ($inspection->lost_items_found && ! empty($payload['lost_items'])) {
                foreach ($payload['lost_items'] as $item) {
                    $lostItems[] = LostFoundItem::query()->create([
                        'item_code' => 'LF-'.now()->format('ymd').'-'.str_pad((string) (LostFoundItem::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                        'trip_id' => $trip?->id,
                        'vehicle_id' => $vehicle->id,
                        'found_location' => 'Kendaraan '.$vehicle->vehicle_code,
                        'found_date' => now()->toDateString(),
                        'item_name' => $item['item_name'] ?? 'Barang tanpa nama',
                        'description' => $item['description'] ?? null,
                        'status' => 'FOUND',
                        'received_by' => $this->context->actorId(),
                    ])->only(['id', 'item_code', 'item_name']);
                }
            }

            if ($odometer !== null) {
                $vehicle->forceFill(['odometer' => $odometer, 'last_service_date' => $vehicle->last_service_date])->save();
                if ($trip) {
                    $trip->forceFill(['end_odometer' => $odometer])->save();
                }
            }

            $this->context->audit('PostTripInspectionRecorded', $inspection, [
                'vehicle_id' => $vehicle->id,
                'condition' => $inspection->vehicle_condition,
                'damage' => $inspection->damage_found,
                'maintenance_required' => $inspection->maintenance_required,
            ]);

            return [
                'inspection_id' => $inspection->id,
                'vehicle_condition' => $inspection->vehicle_condition,
                'damage_found' => $inspection->damage_found,
                'maintenance_required' => $inspection->maintenance_required,
                'maintenance_request' => $maintenanceRequest,
                'lost_items' => $lostItems,
                'cleaning_required' => $inspection->cleaning_required,
            ];
        }, ['source' => 'POSTTRIP']);
        return $result;
    }

    /** Ringkasan inspeksi sebuah trip (§69/§70). */
    public function summary(Trip $trip): array
    {
        $pre = PreTripInspection::query()->where('trip_id', $trip->id)->orderByDesc('id')->first();
        $post = PostTripInspection::query()->where('trip_id', $trip->id)->orderByDesc('id')->first();

        return [
            'pre_trip' => $pre ? [
                'id' => $pre->id,
                'result' => $pre->result,
                'critical_failure' => (bool) $pre->critical_failure,
                'blocks_dispatch' => (bool) $pre->blocks_dispatch,
                'items_checked' => $pre->items_checked,
                'items_failed' => $pre->items_failed,
                'inspected_at' => $pre->inspected_at,
                'failed_items' => PreTripInspectionItem::query()->where('inspection_id', $pre->id)
                    ->whereIn('result', ['FAILED', 'ATTENTION'])
                    ->get()->map(fn ($item): array => [
                        'code' => $item->item_code, 'label' => $item->item_label,
                        'critical' => (bool) $item->critical, 'result' => $item->result,
                    ])->all(),
            ] : null,
            'post_trip' => $post ? [
                'id' => $post->id,
                'condition' => $post->vehicle_condition,
                'damage_found' => (bool) $post->damage_found,
                'maintenance_required' => (bool) $post->maintenance_required,
                'cleaning_required' => (bool) $post->cleaning_required,
                'inspected_at' => $post->inspected_at,
            ] : null,
            'dispatch_blocked' => (bool) ($pre->blocks_dispatch ?? false),
        ];
    }
}
