<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\{AssetGatewayInterface, MaintenanceGatewayInterface};
use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Domain\TransportStatus;
use App\Modules\SchoolOperations\Transportation\Models\{
    VehicleProfile, VehicleDocument, VehicleEquipment, VehicleFuelLog, VehicleRental, VehicleInspection
};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Armada: profil kendaraan (berpijak pada Asset Management), kapasitas,
 * perlengkapan, dokumen + peringatan kedaluwarsa, BBM, inspeksi, dan sewa.
 *
 * Tidak ada master aset kedua: `asset_id` selalu merujuk Asset Management,
 * dan status pemeliharaan dibaca dari modul Maintenance.
 */
class FleetService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly AssetGatewayInterface $assets,
        private readonly MaintenanceGatewayInterface $maintenance,
    ) {
    }

    // ── Kendaraan ─────────────────────────────────────────────────────────

    public function createVehicle(array $payload): VehicleProfile
    {
        $typeCode = strtoupper((string) ($payload['vehicle_type'] ?? 'BUS'));
        $typeConfig = (array) config('transportation.vehicle_types.'.$typeCode, []);

        if (empty($payload['asset_id']) && empty($payload['vehicle_code'])) {
            throw ValidationException::withMessages([
                'vehicle_code' => 'Kode kendaraan wajib diisi (kendaraan tetap harus tertaut ke Asset Management bila tersedia).',
            ]);
        }

        $asset = ! empty($payload['asset_id']) ? $this->assets->find((int) $payload['asset_id']) : null;
        if (! empty($payload['asset_id']) && $asset === null && $this->assets->available()) {
            throw ValidationException::withMessages([
                'asset_id' => 'Aset tidak ditemukan pada Asset Management.',
            ]);
        }

        $studentCapacity = (int) ($payload['student_capacity']
            ?? $payload['total_capacity']
            ?? $typeConfig['passenger_capacity']
            ?? config('transportation.planning.default_vehicle_capacity'));

        $vehicle = VehicleProfile::query()->create([
            'vehicle_code' => $payload['vehicle_code'] ?? ('VEH-'.str_pad((string) (VehicleProfile::query()->count() + 1), 4, '0', STR_PAD_LEFT)),
            'asset_id' => $payload['asset_id'] ?? null,
            'vehicle_type_id' => $payload['vehicle_type_id'] ?? null,
            'vehicle_type' => $typeCode,
            'registration_number' => $payload['registration_number'] ?? null,
            'brand' => $payload['brand'] ?? ($asset['brand'] ?? null),
            'model' => $payload['model'] ?? ($asset['model'] ?? null),
            'manufacture_year' => $payload['manufacture_year'] ?? null,
            'total_capacity' => (int) ($payload['total_capacity'] ?? $studentCapacity),
            'student_capacity' => $studentCapacity,
            'adult_capacity' => (int) ($payload['adult_capacity'] ?? $studentCapacity),
            'standing_capacity' => (int) ($payload['standing_capacity'] ?? ($typeConfig['standing_capacity'] ?? 0)),
            'wheelchair_capacity' => (int) ($payload['wheelchair_capacity'] ?? 0),
            'reserved_capacity' => (int) ($payload['reserved_capacity'] ?? 0),
            'fuel_type' => $payload['fuel_type'] ?? null,
            'transmission' => $payload['transmission'] ?? null,
            'odometer' => $payload['odometer'] ?? 0,
            'status' => strtoupper((string) ($payload['status'] ?? 'AVAILABLE')),
            'campus_id' => $payload['campus_id'] ?? null,
            'ownership_type' => strtoupper((string) ($payload['ownership_type'] ?? 'OWNED')),
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('VehicleCreated', $vehicle, ['vehicle_code' => $vehicle->vehicle_code]);
        $this->context->publish('VehicleAssigned', $vehicle, ['event' => 'VehicleCreated']);

        return $vehicle;
    }

    public function updateVehicle(VehicleProfile $vehicle, array $payload): VehicleProfile
    {
        $before = $vehicle->only(['status', 'student_capacity', 'total_capacity', 'odometer']);
        $vehicle->fill(array_intersect_key($payload, array_flip([
            'registration_number', 'brand', 'model', 'manufacture_year', 'total_capacity', 'student_capacity',
            'adult_capacity', 'standing_capacity', 'wheelchair_capacity', 'reserved_capacity', 'fuel_type',
            'transmission', 'campus_id', 'ownership_type', 'notes', 'vehicle_type',
        ])))->save();

        $this->context->audit('VehicleUpdated', $vehicle, ['before' => $before, 'after' => $vehicle->only(array_keys($before))]);

        return $vehicle->refresh();
    }

    /** Ubah status kendaraan dengan validasi & audit. */
    public function setVehicleStatus(VehicleProfile $vehicle, string $status, ?string $reason = null): VehicleProfile
    {
        $status = strtoupper($status);
        if (! in_array($status, TransportStatus::VEHICLE_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Status kendaraan tidak dikenal: '.$status]);
        }

        $previous = $vehicle->status;
        $vehicle->forceFill(['status' => $status, 'status_changed_at' => now()])->save();

        $this->context->audit('VehicleStatusChanged', $vehicle, [
            'from' => $previous, 'to' => $status, 'reason' => $reason,
        ]);

        return $vehicle->refresh();
    }

    public function vehicles(array $filters = []): array
    {
        return VehicleProfile::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->when($filters['campus_id'] ?? null, fn ($q, $campus) => $q->where('campus_id', $campus))
            ->when($filters['vehicle_type'] ?? null, fn ($q, $type) => $q->where('vehicle_type', strtoupper($type)))
            ->when($filters['ownership_type'] ?? null, fn ($q, $ownership) => $q->where('ownership_type', strtoupper($ownership)))
            ->orderBy('vehicle_code')
            ->get()
            ->map(fn (VehicleProfile $vehicle): array => $this->vehicleSnapshot($vehicle))
            ->all();
    }

    public function vehicleSnapshot(VehicleProfile $vehicle): array
    {
        $documents = VehicleDocument::query()->where('vehicle_id', $vehicle->id)->get();
        $equipment = VehicleEquipment::query()->where('vehicle_id', $vehicle->id)->get();
        $maintenance = $this->maintenance->statusForAsset($vehicle->asset_id, $vehicle->id);

        $assignedDriver = \App\Modules\SchoolOperations\Transportation\Models\DriverProfile::query()
            ->where('assigned_vehicle_id', $vehicle->id)
            ->first();

        return [
            'id' => $vehicle->id,
            'vehicle_code' => $vehicle->vehicle_code,
            'assigned_driver' => $assignedDriver?->driver_code,
            'asset_id' => $vehicle->asset_id,
            'vehicle_type' => $vehicle->vehicle_type,
            'registration_number' => $vehicle->registration_number,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'status' => $vehicle->status,
            'ownership_type' => $vehicle->ownership_type,
            'odometer' => (float) $vehicle->odometer,
            'capacity' => $this->capacityBreakdown($vehicle),
            'documents' => [
                'total' => $documents->count(),
                'expired' => $documents->filter(fn (VehicleDocument $doc): bool => $doc->expiry_date && $doc->expiry_date->isPast())->count(),
                'expiring_soon' => $documents->filter(fn (VehicleDocument $doc): bool => $doc->expiry_date
                    && ! $doc->expiry_date->isPast()
                    && $doc->expiry_date->diffInDays(now()) <= (int) $doc->reminder_days)->count(),
            ],
            'equipment' => [
                'total' => $equipment->count(),
                'problem' => $equipment->whereIn('status', ['MISSING', 'DAMAGED', 'EXPIRED'])->count(),
                'critical_problem' => $equipment->where('critical', true)->whereIn('status', ['MISSING', 'DAMAGED', 'EXPIRED'])->count(),
            ],
            'maintenance' => $maintenance,
        ];
    }

    public function capacityBreakdown(VehicleProfile $vehicle): array
    {
        $reserved = (int) $vehicle->reserved_capacity;
        $seatCapacity = (int) $vehicle->student_capacity;

        return [
            'total_capacity' => (int) $vehicle->total_capacity,
            'student_capacity' => $seatCapacity,
            'adult_capacity' => (int) $vehicle->adult_capacity,
            'standing_capacity' => (int) $vehicle->standing_capacity,
            'wheelchair_capacity' => (int) $vehicle->wheelchair_capacity,
            'reserved_capacity' => $reserved,
            'usable_capacity' => max(0, $seatCapacity - $reserved),
        ];
    }

    /**
     * §8/§87 Rule 6: jumlah penumpang tidak boleh melebihi kapasitas kecuali
     * override berwenang dengan alasan yang tercatat.
     */
    public function validateCapacity(VehicleProfile $vehicle, int $passengers, bool $allowOverride = false, ?string $reason = null): array
    {
        $capacity = $this->capacityBreakdown($vehicle)['usable_capacity'];
        $exceeds = $passengers > $capacity;

        return [
            'allowed' => ! $exceeds || ($allowOverride && $reason !== null),
            'capacity' => $capacity,
            'passengers' => $passengers,
            'available_seats' => max(0, $capacity - $passengers),
            'exceeds' => $exceeds,
            'override' => $exceeds && $allowOverride && $reason !== null,
            'override_reason' => $exceeds ? $reason : null,
            'reason' => $exceeds && ! $allowOverride
                ? sprintf('Penumpang %d melebihi kapasitas %d kursi.', $passengers, $capacity)
                : null,
        ];
    }

    // ── Perlengkapan (§9) ──────────────────────────────────────────────────

    public function syncEquipment(VehicleProfile $vehicle, array $items, ?int $actorId = null): array
    {
        $config = (array) config('transportation.equipment_types', []);
        $saved = [];

        foreach ($items as $item) {
            $type = strtoupper((string) ($item['equipment_type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $defaults = (array) ($config[$type] ?? []);
            $record = VehicleEquipment::query()->updateOrCreate(
                ['vehicle_id' => $vehicle->id, 'equipment_type' => $type],
                [
                    'label' => $item['label'] ?? ($defaults['label'] ?? $type),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'status' => strtoupper((string) ($item['status'] ?? 'AVAILABLE')),
                    'mandatory' => (bool) ($item['mandatory'] ?? ($defaults['mandatory'] ?? false)),
                    'critical' => (bool) ($item['critical'] ?? ($defaults['critical'] ?? false)),
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'last_checked_at' => now()->toDateString(),
                    'notes' => $item['notes'] ?? null,
                ]
            );
            $saved[] = $record;
        }

        $this->context->audit('VehicleEquipmentSynced', $vehicle, ['items' => count($saved)]);

        return array_map(fn (VehicleEquipment $item): array => $item->toArray(), $saved);
    }

    /** Perlengkapan bermasalah yang bersifat kritis → memblokir dispatch. */
    public function equipmentBlockers(VehicleProfile $vehicle): array
    {
        return VehicleEquipment::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('critical', true)
            ->whereIn('status', ['MISSING', 'DAMAGED', 'EXPIRED'])
            ->get()
            ->map(fn (VehicleEquipment $item): array => [
                'equipment_type' => $item->equipment_type,
                'status' => $item->status,
                'label' => $item->label,
            ])
            ->all();
    }

    // ── Dokumen (§10) ──────────────────────────────────────────────────────

    public function addDocument(VehicleProfile $vehicle, array $payload): VehicleDocument
    {
        $type = strtoupper((string) ($payload['document_type'] ?? 'OTHER'));
        $config = (array) config('transportation.document_types.'.$type, []);

        $document = VehicleDocument::query()->create([
            'vehicle_id' => $vehicle->id,
            'document_type' => $type,
            'document_number' => $payload['document_number'] ?? null,
            'issue_date' => $payload['issue_date'] ?? null,
            'expiry_date' => $payload['expiry_date'] ?? null,
            'file_path' => $payload['file_path'] ?? null,
            'verification_status' => strtoupper((string) ($payload['verification_status'] ?? 'PENDING')),
            'blocks_dispatch' => (bool) ($payload['blocks_dispatch'] ?? ($config['blocks_dispatch'] ?? false)),
            'reminder_days' => (int) ($payload['reminder_days'] ?? ($config['reminder_days'] ?? 30)),
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('VehicleDocumentAdded', $document, ['document_type' => $type]);

        return $document;
    }

    public function verifyDocument(VehicleDocument $document, bool $verified, ?int $actorId = null): VehicleDocument
    {
        $document->forceFill([
            'verification_status' => $verified ? 'VERIFIED' : 'REJECTED',
            'verified_by' => $actorId,
            'verified_at' => now(),
        ])->save();

        $this->context->audit('VehicleDocumentVerified', $document, ['status' => $document->verification_status]);

        return $document->refresh();
    }

    /** Dokumen yang kedaluwarsa / hampir kedaluwarsa (§10). */
    public function expiringDocuments(int $withinDays = 30, ?int $vehicleId = null): array
    {
        $limit = now()->addDays($withinDays)->toDateString();

        return VehicleDocument::query()
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limit)
            ->orderBy('expiry_date')
            ->get()
            ->map(function (VehicleDocument $document): array {
                $expired = $document->expiry_date->isPast();

                return [
                    'id' => $document->id,
                    'vehicle_id' => $document->vehicle_id,
                    'document_type' => $document->document_type,
                    'expiry_date' => $document->expiry_date->toDateString(),
                    'days_left' => (int) now()->startOfDay()->diffInDays($document->expiry_date->startOfDay(), false),
                    'expired' => $expired,
                    'blocks_dispatch' => (bool) $document->blocks_dispatch,
                    'verification_status' => $document->verification_status,
                ];
            })
            ->all();
    }

    /** Dokumen bermasalah yang memblokir dispatch (§32). */
    public function documentBlockers(VehicleProfile $vehicle): array
    {
        return VehicleDocument::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('blocks_dispatch', true)
            ->where(function ($query): void {
                $query->whereNull('expiry_date')
                    ->orWhere('expiry_date', '<', now()->toDateString())
                    ->orWhere('verification_status', '!=', 'VERIFIED');
            })
            ->get()
            ->map(fn (VehicleDocument $document): array => [
                'document_type' => $document->document_type,
                'expiry_date' => $document->expiry_date?->toDateString(),
                'verification_status' => $document->verification_status,
            ])
            ->all();
    }

    /**
     * Kelayakan kendaraan untuk ditugaskan (§57 + §87 Rule 1).
     */
    public function eligibility(VehicleProfile $vehicle, array $options = []): array
    {
        $blockers = [];
        $status = strtoupper((string) $vehicle->status);

        if (TransportStatus::vehicleBlocksDispatch($status)) {
            $blockers[] = ['code' => 'VEHICLE_STATUS', 'message' => 'Status kendaraan '.$status.' tidak dapat ditugaskan.', 'severity' => 'HIGH'];
        }

        $maintenance = $this->maintenance->statusForAsset($vehicle->asset_id, $vehicle->id);
        if ($maintenance['in_maintenance']) {
            $blockers[] = [
                'code' => 'MAINTENANCE',
                'message' => 'Kendaraan memiliki '.$maintenance['open_work_order_count'].' work order terbuka.',
                'severity' => 'HIGH',
                'work_orders' => $maintenance['open_work_orders'],
            ];
        }

        foreach ($this->documentBlockers($vehicle) as $document) {
            $blockers[] = [
                'code' => 'DOCUMENT',
                'message' => 'Dokumen '.$document['document_type'].' belum valid.',
                'severity' => 'HIGH',
                'document' => $document,
            ];
        }

        foreach ($this->equipmentBlockers($vehicle) as $equipment) {
            $blockers[] = [
                'code' => 'EQUIPMENT',
                'message' => 'Perlengkapan kritis '.$equipment['label'].' berstatus '.$equipment['status'].'.',
                'severity' => 'HIGH',
                'equipment' => $equipment,
            ];
        }

        $capacity = null;
        if (isset($options['passengers'])) {
            $capacity = $this->validateCapacity(
                $vehicle,
                (int) $options['passengers'],
                (bool) ($options['allow_override'] ?? false),
                $options['override_reason'] ?? null
            );
            if (! $capacity['allowed']) {
                $blockers[] = ['code' => 'CAPACITY', 'message' => $capacity['reason'], 'severity' => 'HIGH', 'capacity' => $capacity];
            }
        }

        return [
            'eligible' => $blockers === [],
            'vehicle_id' => $vehicle->id,
            'status' => $vehicle->status,
            'blockers' => $blockers,
            'capacities' => $this->capacityBreakdown($vehicle),
            'capacity_check' => $capacity,
            'maintenance' => $maintenance,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    // ── BBM (§58/§59) ──────────────────────────────────────────────────────

    public function logFuel(array $payload, ?string $idempotencyKey = null): array
    {
        $vehicle = VehicleProfile::query()->findOrFail((int) $payload['vehicle_id']);

        $result = $this->context->idempotent('FUEL_ENTRY', $idempotencyKey, function () use ($vehicle, $payload, $idempotencyKey): array {
            $previous = VehicleFuelLog::query()
                ->where('vehicle_id', $vehicle->id)
                ->orderByDesc('odometer')
                ->first();

            $odometer = isset($payload['odometer']) ? (float) $payload['odometer'] : null;
            if ($odometer !== null && $previous && $previous->odometer !== null && $odometer < (float) $previous->odometer) {
                throw ValidationException::withMessages([
                    'odometer' => 'Odometer tidak boleh lebih kecil dari pencatatan sebelumnya ('.$previous->odometer.').',
                ]);
            }

            $quantity = (float) ($payload['fuel_quantity'] ?? 0);
            $cost = (float) ($payload['fuel_cost'] ?? 0);
            $distance = $previous && $odometer !== null && $previous->odometer !== null
                ? round($odometer - (float) $previous->odometer, 2)
                : null;

            $log = VehicleFuelLog::query()->create([
                'vehicle_id' => $vehicle->id,
                'trip_id' => $payload['trip_id'] ?? null,
                'driver_profile_id' => $payload['driver_profile_id'] ?? null,
                'fuel_type' => $payload['fuel_type'] ?? $vehicle->fuel_type,
                'fuel_quantity' => $quantity,
                'fuel_cost' => $cost,
                'price_per_unit' => $quantity > 0 ? round($cost / $quantity, 2) : null,
                'odometer' => $odometer,
                'fuel_date' => $payload['fuel_date'] ?? now()->toDateString(),
                'station' => $payload['station'] ?? null,
                'payment_method' => $payload['payment_method'] ?? null,
                'receipt_number' => $payload['receipt_number'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'recorded_by' => $this->context->actorId(),
            ]);

            if ($odometer !== null) {
                $vehicle->forceFill(['odometer' => $odometer])->save();
            }

            $this->context->audit('VehicleFuelLogged', $log, [
                'vehicle_id' => $vehicle->id, 'quantity' => $quantity, 'cost' => $cost,
            ]);

            return [
                'fuel_log_id' => $log->id,
                'efficiency_km_per_liter' => ($distance !== null && $quantity > 0) ? round($distance / $quantity, 2) : null,
                'distance_since_last_km' => $distance,
                'cost_per_km' => ($distance !== null && $distance > 0) ? round($cost / $distance, 2) : null,
                'price_per_unit' => $log->price_per_unit,
            ];
        }, ['source' => 'FUEL', 'external_reference' => $payload['receipt_number'] ?? null]);

        return $result;
    }

    /** Konsumsi BBM agregat per kendaraan (§80). */
    public function fuelMetrics(int $vehicleId, ?string $from = null, ?string $to = null): array
    {
        $logs = VehicleFuelLog::query()
            ->where('vehicle_id', $vehicleId)
            ->when($from, fn ($q) => $q->whereDate('fuel_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('fuel_date', '<=', $to))
            ->orderBy('fuel_date')
            ->get();

        $quantity = (float) $logs->sum('fuel_quantity');
        $cost = (float) $logs->sum('fuel_cost');
        $first = $logs->first();
        $last = $logs->last();
        $distance = ($first && $last && $first->odometer !== null && $last->odometer !== null)
            ? round((float) $last->odometer - (float) $first->odometer, 2)
            : null;

        return [
            'entries' => $logs->count(),
            'total_quantity' => round($quantity, 2),
            'total_cost' => round($cost, 2),
            'distance_km' => $distance,
            'efficiency_km_per_liter' => $distance && $quantity > 0 ? round($distance / $quantity, 2) : null,
            'cost_per_km' => $distance && $distance > 0 ? round($cost / $distance, 2) : null,
            'average_price_per_unit' => $quantity > 0 ? round($cost / $quantity, 2) : null,
        ];
    }

    // ── Inspeksi & sewa ────────────────────────────────────────────────────

    public function recordInspection(array $payload): VehicleInspection
    {
        $inspection = VehicleInspection::query()->create(array_intersect_key($payload, array_flip([
            'vehicle_id', 'inspection_type', 'inspection_date', 'next_inspection_date', 'result',
            'workshop', 'work_order_id', 'cost', 'findings', 'inspected_by',
        ])) + ['inspected_by' => $payload['inspected_by'] ?? $this->context->actorId()]);

        $this->context->audit('VehicleInspected', $inspection, ['result' => $inspection->result]);

        return $inspection;
    }

    public function createRental(array $payload): VehicleRental
    {
        $rental = VehicleRental::query()->create(array_intersect_key($payload, array_flip([
            'vehicle_id', 'vendor_id', 'vendor_name', 'contract_number', 'rental_start', 'rental_end',
            'rental_cost', 'cost_basis', 'insurance_reference', 'driver_arrangement', 'status', 'notes',
        ])));

        $this->context->audit('VehicleRentalCreated', $rental, ['contract' => $rental->contract_number]);

        return $rental;
    }

    /** Kendaraan yang tersedia untuk rentang waktu tertentu. */
    public function availableVehicles(array $filters = []): array
    {
        $occupancy = DB::table('trips')
            ->select('vehicle_id', DB::raw('count(*) as total'))
            ->whereIn('status', ['PLANNED', 'DISPATCHED', 'BOARDING', 'IN_TRANSIT', 'ARRIVED', 'DELAYED'])
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->whereDate('trip_date', $date))
            ->groupBy('vehicle_id')
            ->pluck('total', 'vehicle_id')
            ->all();

        return VehicleProfile::query()
            ->whereNotIn('status', TransportStatus::VEHICLE_BLOCKING_STATUSES)
            ->orderBy('vehicle_code')
            ->get()
            ->map(fn (VehicleProfile $vehicle): array => $this->vehicleSnapshot($vehicle) + [
                'active_trips' => (int) ($occupancy[$vehicle->id] ?? 0),
            ])
            ->values()
            ->all();
    }
}
