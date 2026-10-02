<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Domain\TransportStatus;
use App\Modules\SchoolOperations\Transportation\Models\{
    DriverProfile, PassengerTransfer, RouteStop, ScheduleException, TransportationRoute, TransportationSchedule,
    Trip, TripAssignment, TripDelayReport, TripManifest, TripPassenger, TripStop, TripStopEvent, VehicleProfile
};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Perjalanan (§30-§32, §41-§44, §49-§52): pembentukan trip dari jadwal atau
 * ad-hoc, penugasan kendaraan/pengemudi dengan validasi, dispatch bergerbang,
 * operasi per halte dengan rencana vs aktual, penggantian, dan penutupan trip.
 */
class TripService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly ManifestService $manifest,
        private readonly FleetService $fleet,
        private readonly DriverService $drivers,
        private readonly InspectionService $inspections,
        private readonly TransportationNotificationService $notifications,
    ) {
    }

    // ── Pembentukan trip ───────────────────────────────────────────────────

    public function createFromSchedule(TransportationSchedule $schedule, string $date, array $overrides = []): Trip
    {
        $existing = Trip::query()
            ->where('schedule_id', $schedule->id)
            ->whereDate('trip_date', $date)
            ->first();
        if ($existing) {
            return $existing;
        }

        $route = TransportationRoute::query()->findOrFail($schedule->route_id);
        $departure = (string) ($overrides['departure_time'] ?? $schedule->departure_time);
        $arrival = (string) ($overrides['arrival_time'] ?? $schedule->expected_arrival_time ?? $departure);
        $start = CarbonImmutable::parse($date.' '.$departure);
        $end = CarbonImmutable::parse($date.' '.$arrival);
        if ($end->lt($start)) {
            $end = $end->addDay();
        }

        $trip = Trip::query()->create([
            'trip_number' => $this->nextTripNumber($date),
            'route_id' => $route->id,
            'route_version_id' => $route->current_version_id,
            'schedule_id' => $schedule->id,
            'service_id' => $schedule->service_id ?? $route->service_id,
            'campus_id' => $route->campus_id,
            'trip_date' => $date,
            'direction' => $schedule->direction,
            'planned_departure' => $departure,
            'planned_arrival' => $arrival,
            'planned_start_at' => $start,
            'planned_end_at' => $end,
            'status' => 'PLANNED',
            'trip_type' => 'REGULAR',
            'vehicle_id' => $schedule->default_vehicle_id,
            'driver_profile_id' => $schedule->default_driver_profile_id,
        ]);

        $this->buildTripStops($trip, $route);
        $this->manifest->generate($trip);

        if ($schedule->default_vehicle_id) {
            $vehicle = VehicleProfile::query()->find($schedule->default_vehicle_id);
            if ($vehicle) {
                $this->assignVehicle($trip, $vehicle, ['notify' => false, 'source' => 'SCHEDULE_DEFAULT']);
            }
        }

        $this->context->publish('TripCreated', $trip, [
            'trip_number' => $trip->trip_number,
            'route_id' => $route->id,
            'date' => $date,
            'exception_id' => $overrides['exception_id'] ?? null,
        ]);

        return $trip->refresh();
    }

    /** Trip ad-hoc: kegiatan, lomba, field trip, darurat (§55/§64). */
    public function createAdHoc(array $payload): Trip
    {
        $route = TransportationRoute::query()->findOrFail((int) $payload['route_id']);
        $date = $payload['trip_date'] ?? now()->toDateString();
        $departure = $payload['planned_departure'] ?? '06:00';
        $arrival = $payload['planned_arrival'] ?? $departure;
        $start = CarbonImmutable::parse($date.' '.$departure);

        $trip = Trip::query()->create([
            'trip_number' => $this->nextTripNumber($date),
            'route_id' => $route->id,
            'route_version_id' => $route->current_version_id,
            'schedule_id' => null,
            'service_id' => $payload['service_id'] ?? $route->service_id,
            'campus_id' => $route->campus_id,
            'trip_date' => $date,
            'direction' => strtoupper((string) ($payload['direction'] ?? 'PICKUP')),
            'planned_departure' => $departure,
            'planned_arrival' => $arrival,
            'planned_start_at' => $start,
            'planned_end_at' => CarbonImmutable::parse($date.' '.$arrival)->lt($start)
                ? CarbonImmutable::parse($date.' '.$arrival)->addDay()
                : CarbonImmutable::parse($date.' '.$arrival),
            'status' => 'PLANNED',
            'is_ad_hoc' => true,
            'trip_type' => strtoupper((string) ($payload['trip_type'] ?? 'EVENT')),
            'event_reference_id' => $payload['event_reference_id'] ?? null,
            'event_reference_type' => $payload['event_reference_type'] ?? null,
            'planned_passengers' => (int) ($payload['planned_passengers'] ?? 0),
        ]);

        $this->buildTripStops($trip, $route);

        if (! empty($payload['passengers'])) {
            foreach ($payload['passengers'] as $passenger) {
                $this->manifest->addPassenger($trip, $passenger);
            }
        }

        $this->context->publish('TripCreated', $trip, ['trip_number' => $trip->trip_number, 'ad_hoc' => true, 'type' => $trip->trip_type]);

        return $trip->refresh();
    }

    private function nextTripNumber(string $date): string
    {
        $prefix = 'TRP-'.CarbonImmutable::parse($date)->format('ymd');
        $count = Trip::query()->where('trip_number', 'like', $prefix.'%')->count();

        return $prefix.'-'.str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);
    }

    /** Bentuk halte trip dari halte rute beserta jadwal rencananya (§41). */
    private function buildTripStops(Trip $trip, TransportationRoute $route): array
    {
        $stops = RouteStop::query()->where('route_id', $route->id)->orderBy('sequence')->get();
        $date = $trip->trip_date->toDateString();
        $created = [];

        foreach ($stops as $stop) {
            $plannedArrival = $stop->planned_arrival ? CarbonImmutable::parse($date.' '.$stop->planned_arrival) : null;
            $plannedDeparture = $stop->planned_departure ? CarbonImmutable::parse($date.' '.$stop->planned_departure) : null;
            if ($plannedArrival && $trip->planned_start_at && $plannedArrival->lt($trip->planned_start_at)) {
                $plannedArrival = $plannedArrival->addDay();
            }
            if ($plannedDeparture && $plannedArrival && $plannedDeparture->lt($plannedArrival)) {
                $plannedDeparture = $plannedDeparture->addDay();
            }

            $created[] = TripStop::query()->create([
                'trip_id' => $trip->id,
                'route_stop_id' => $stop->id,
                'sequence' => $stop->sequence,
                'name' => $stop->name,
                'stop_type' => $stop->stop_type,
                'latitude' => $stop->latitude,
                'longitude' => $stop->longitude,
                'planned_arrival_at' => $plannedArrival,
                'planned_departure_at' => $plannedDeparture,
                'estimated_arrival_at' => $plannedArrival,
                'status' => 'PENDING',
            ]);
        }

        return $created;
    }

    // ── Penugasan kendaraan (§50, §63) ─────────────────────────────────────

    public function assignVehicle(Trip $trip, VehicleProfile $vehicle, array $options = []): array
    {
        $override = (bool) ($options['allow_override'] ?? false);
        $reason = $options['reason'] ?? null;

        $eligibility = $this->fleet->eligibility($vehicle, [
            'passengers' => max((int) $trip->planned_passengers, (int) $trip->boarded_count),
            'allow_override' => $override,
            'override_reason' => $reason,
        ]);

        $conflict = $this->vehicleConflict($vehicle, $trip);
        if ($conflict !== null) {
            $eligibility['blockers'][] = [
                'code' => 'DOUBLE_BOOKED',
                'message' => 'Kendaraan sudah ditugaskan pada trip lain yang waktunya bertumpang tindih.',
                'severity' => 'HIGH',
                'conflict_trip_id' => $conflict->id,
            ];
            $eligibility['eligible'] = false;
        }

        if (! $eligibility['eligible'] && ! $override) {
            $this->context->audit('VehicleAssignmentRejected', $trip, [
                'vehicle_id' => $vehicle->id, 'blockers' => array_column($eligibility['blockers'], 'code'),
            ]);

            return ['assigned' => false, 'eligibility' => $eligibility, 'reason' => $eligibility['blockers'][0]['message'] ?? 'Kendaraan tidak memenuhi syarat.'];
        }

        $assignment = TripAssignment::query()->create([
            'trip_id' => $trip->id,
            'assignment_type' => 'VEHICLE',
            'vehicle_id' => $vehicle->id,
            'assignment_role' => $options['role'] ?? 'PRIMARY',
            'replaces_assignment_id' => $options['replaces_assignment_id'] ?? null,
            'reason' => $reason,
            'ownership_type' => $vehicle->ownership_type,
            'validation' => $eligibility,
            'assigned_by' => $this->context->actorId(),
            'approved_by' => $options['approved_by'] ?? null,
            'approved_at' => isset($options['approved_by']) && $override ? now() : null,
            'assigned_at' => now(),
            'status' => 'ACTIVE',
        ]);

        $trip->forceFill([
            'vehicle_id' => $vehicle->id,
            'override_reason' => (! $eligibility['eligible'] && $override) ? $reason : $trip->override_reason,
            'override_by' => (! $eligibility['eligible'] && $override) ? $this->context->actorId() : $trip->override_by,
        ])->save();

        if ($trip->status === 'PLANNED') {
            $trip->forceFill(['status' => 'VEHICLE_ASSIGNED'])->save();
        }

        $vehicle->forceFill(['status' => 'ASSIGNED'])->save();
        $this->manifest->generate($trip);

        $this->context->audit('VehicleAssigned', $trip, [
            'vehicle_id' => $vehicle->id, 'role' => $options['role'] ?? 'PRIMARY', 'override' => ! $eligibility['eligible'],
        ]);
        $this->context->publish('VehicleAssigned', $trip, [
            'trip_number' => $trip->trip_number, 'vehicle_id' => $vehicle->id, 'override' => ! $eligibility['eligible'],
        ]);

        return ['assigned' => true, 'assignment_id' => $assignment->id, 'eligibility' => $eligibility];
    }

    private function vehicleConflict(VehicleProfile $vehicle, Trip $trip): ?Trip
    {
        return Trip::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('id', '!=', $trip->id)
            ->whereNotIn('status', ['CANCELLED', 'COMPLETED'])
            ->where('planned_start_at', '<', $trip->planned_end_at ?? $trip->planned_start_at?->copy()->addHours(2))
            ->where('planned_end_at', '>', $trip->planned_start_at ?? now())
            ->first();
    }

    /** Penggantian kendaraan saat mogok/insiden (§49/§50) — trip asli dipertahankan. */
    public function replaceVehicle(Trip $trip, VehicleProfile $replacement, string $reason, bool $transferPassengers = true, ?int $approvedBy = null): array
    {
        $original = TripAssignment::query()
            ->where('trip_id', $trip->id)
            ->where('assignment_type', 'VEHICLE')
            ->where('status', 'ACTIVE')
            ->first();

        $result = $this->assignVehicle($trip, $replacement, [
            'role' => 'REPLACEMENT',
            'reason' => $reason,
            'replaces_assignment_id' => $original?->id,
            'approved_by' => $approvedBy,
            'allow_override' => true,
        ]);

        if (! ($result['assigned'] ?? false)) {
            return $result;
        }

        if ($original) {
            $original->forceFill(['status' => 'REPLACED'])->save();
        }

        $originalVehicle = $original ? VehicleProfile::query()->find($original->vehicle_id) : null;
        if ($originalVehicle && $originalVehicle->status !== 'MAINTENANCE') {
            $originalVehicle->forceFill(['status' => 'AVAILABLE'])->save();
        }

        $transfer = null;
        if ($transferPassengers) {
            $passengerIds = TripPassenger::query()->where('trip_id', $trip->id)->pluck('student_id')->all();
            $transfer = PassengerTransfer::query()->create([
                'trip_id' => $trip->id,
                'from_vehicle_id' => $original?->vehicle_id,
                'to_vehicle_id' => $replacement->id,
                'from_driver_profile_id' => $trip->driver_profile_id,
                'to_driver_profile_id' => $trip->driver_profile_id,
                'passenger_count' => count($passengerIds),
                'reason' => $reason,
                'transferred_at' => now(),
                'authorized_by' => $approvedBy ?? $this->context->actorId(),
                'passenger_ids' => $passengerIds,
            ]);
        }

        $this->manifest->generate($trip, ['rebuild' => true]);

        $this->context->audit('VehicleReplaced', $trip, [
            'original_vehicle_id' => $original?->vehicle_id,
            'replacement_vehicle_id' => $replacement->id,
            'reason' => $reason,
        ]);
        $this->context->publish('VehicleReplaced', $trip, [
            'original' => $original?->vehicle_id, 'replacement' => $replacement->id, 'reason' => $reason,
        ]);

        foreach (TripPassenger::query()->where('trip_id', $trip->id)->pluck('student_id')->unique() as $studentId) {
            $this->notifications->toGuardians('VehicleChanged', (int) $studentId,
                'Kendaraan pengganti',
                'Kendaraan untuk perjalanan '.$trip->trip_number.' diganti menjadi '.$replacement->vehicle_code.'. Alasan: '.$reason.'.',
                ['trip_id' => $trip->id, 'idempotency_key' => 'vehicle-change-'.$trip->id.'-'.$replacement->id.'-'.$studentId]
            );
        }

        return $result + [
            'original_vehicle_id' => $original?->vehicle_id,
            'replacement_vehicle_id' => $replacement->id,
            'replacement_vehicle_code' => $replacement->vehicle_code,
            'transfer_id' => $transfer?->id,
            'passengers_transferred' => $transfer?->passenger_count,
        ];
    }

    // ── Dispatch (§32 + §87) ───────────────────────────────────────────────

    /**
     * Validasi gerbang dispatch. Kegagalan memblokir keberangkatan kecuali
     * override berwenang disertai alasan yang diaudit.
     */
    public function validateDispatch(Trip $trip): array
    {
        $trip->loadMissing(['route', 'vehicle', 'driver']);
        $blockers = [];
        $warnings = [];
        $dispatchConfig = (array) config('transportation.dispatch', []);

        if (! $trip->route_id) {
            $blockers[] = ['code' => 'NO_ROUTE', 'message' => 'Trip belum memiliki rute.', 'severity' => 'HIGH'];
        } elseif (! $trip->route?->active) {
            $blockers[] = ['code' => 'INACTIVE_ROUTE', 'message' => 'Rute tidak aktif.', 'severity' => 'HIGH'];
        }

        if (! $trip->driver_profile_id) {
            $blockers[] = ['code' => 'NO_DRIVER', 'message' => 'Trip belum memiliki pengemudi.', 'severity' => 'HIGH'];
        }
        if (! $trip->vehicle_id) {
            $blockers[] = ['code' => 'NO_VEHICLE', 'message' => 'Trip belum memiliki kendaraan.', 'severity' => 'HIGH'];
        }

        if ($trip->driver) {
            $driverEligibility = $this->drivers->eligibility($trip->driver, [
                'vehicle_type' => $trip->vehicle?->vehicle_type ?? 'BUS',
                'date' => $trip->trip_date?->toDateString(),
                'starts_at' => $trip->planned_start_at,
                'ends_at' => $trip->planned_end_at,
                'exclude_trip_id' => $trip->id,
            ]);
            foreach ($driverEligibility['blockers'] as $blocker) {
                $blockers[] = $blocker + ['source' => 'DRIVER'];
            }
        }

        if ($trip->vehicle) {
            $vehicleEligibility = $this->fleet->eligibility($trip->vehicle, [
                'passengers' => max((int) $trip->planned_passengers, (int) $trip->boarded_count),
            ]);
            foreach ($vehicleEligibility['blockers'] as $blocker) {
                if (($blocker['code'] ?? '') === 'CAPACITY') {
                    $blockers[] = $blocker + ['source' => 'VEHICLE'];
                } else {
                    $blockers[] = $blocker + ['source' => 'VEHICLE'];
                }
            }
        }

        $manifest = TripManifest::query()->where('trip_id', $trip->id)->first();
        $capacity = (int) optional($trip->vehicle)->student_capacity;
        if ($manifest && $capacity > 0 && $manifest->expected_passengers > $capacity) {
            $blockers[] = [
                'code' => 'MANIFEST_OVER_CAPACITY',
                'message' => 'Manifes '.$manifest->expected_passengers.' penumpang melebihi kapasitas '.$capacity.'.',
                'severity' => 'HIGH',
            ];
        }

        $inspection = $this->inspections->summary($trip);
        if ($dispatchConfig['require_pretrip_inspection'] ?? true) {
            if (! $inspection['pre_trip']) {
                $blockers[] = ['code' => 'NO_PRETRIP', 'message' => 'Checklist pra-trip belum dilakukan.', 'severity' => 'HIGH'];
            } elseif ($inspection['dispatch_blocked']) {
                $blockers[] = [
                    'code' => 'PRETRIP_CRITICAL',
                    'message' => 'Checklist pra-trip menunjukkan kegagalan kritis.',
                    'severity' => 'CRITICAL',
                    'failed_items' => $inspection['pre_trip']['failed_items'],
                ];
            }
        }

        if ($trip->vehicle && $trip->vehicle->status === 'MAINTENANCE') {
            $blockers[] = ['code' => 'VEHICLE_MAINTENANCE', 'message' => 'Kendaraan sedang dalam pemeliharaan.', 'severity' => 'HIGH'];
        }

        if (! $manifest) {
            $warnings[] = ['code' => 'NO_MANIFEST', 'message' => 'Manifes belum dibentuk; akan dibentuk saat dispatch.'];
        }

        return [
            'dispatchable' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    public function dispatch(Trip $trip, array $options = []): array
    {
        if (in_array($trip->status, ['COMPLETED', 'CANCELLED'], true)) {
            return ['dispatched' => false, 'reason' => 'Trip sudah '.$trip->status.'.'];
        }

        $validation = $this->validateDispatch($trip);
        $override = (bool) ($options['allow_override'] ?? false);
        $reason = $options['reason'] ?? null;

        if (! $validation['dispatchable'] && (! $override || $reason === null)) {
            $this->context->audit('TripDispatchBlocked', $trip, [
                'blockers' => array_column($validation['blockers'], 'code'),
            ]);

            return [
                'dispatched' => false,
                'blocked' => true,
                'validation' => $validation,
                'reason' => 'Dispatch diblokir: '.implode('; ', array_column($validation['blockers'], 'message')),
            ];
        }

        if (! $validation['dispatchable'] && $override) {
            $allowedRoles = (array) config('transportation.dispatch.override_roles', []);
            $this->context->audit('TripDispatchOverride', $trip, [
                'reason' => $reason, 'blockers' => array_column($validation['blockers'], 'code'),
                'actor_roles' => $allowedRoles,
            ]);
        }

        if (! $trip->manifest) {
            $this->manifest->generate($trip);
        }

        $trip->forceFill([
            'status' => 'DISPATCHED',
            'dispatched_at' => now(),
            'dispatched_by' => $this->context->actorId(),
            'dispatch_validation' => $validation,
            'override_reason' => (! $validation['dispatchable'] && $override) ? $reason : $trip->override_reason,
            'override_by' => (! $validation['dispatchable'] && $override) ? $this->context->actorId() : $trip->override_by,
        ])->save();

        if ($trip->vehicle) {
            $trip->vehicle->forceFill(['status' => 'ON_TRIP'])->save();
        }
        if ($trip->driver) {
            $trip->driver->forceFill(['driver_status' => 'ON_TRIP'])->save();
        }

        $this->context->publish('TripDispatched', $trip, [
            'trip_number' => $trip->trip_number,
            'vehicle_id' => $trip->vehicle_id,
            'driver_profile_id' => $trip->driver_profile_id,
            'override' => ! $validation['dispatchable'],
        ]);

        if ($trip->driver?->user_id) {
            $this->notifications->dispatch('TripAssignment', [(int) $trip->driver->user_id],
                'Perjalanan '.$trip->trip_number.' siap',
                'Trip '.$trip->trip_number.' telah didispatch. Silakan buka aplikasi pengemudi.',
                ['audience' => 'DRIVER', 'trip_id' => $trip->id, 'idempotency_key' => 'dispatch-'.$trip->id]
            );
        }

        return ['dispatched' => true, 'validation' => $validation, 'override' => ! $validation['dispatchable'], 'trip' => $this->tripSnapshot($trip->refresh())];
    }

    // ── Operasi trip ───────────────────────────────────────────────────────

    public function start(Trip $trip, array $data = [], ?string $idempotencyKey = null): array
    {
        $result = $this->context->idempotent('TRIP_START', $idempotencyKey, function () use ($trip, $data): array {
            if (! in_array($trip->status, ['DISPATCHED', 'VEHICLE_ASSIGNED', 'DRIVER_ASSIGNED', 'DELAYED', 'INCIDENT'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Trip berstatus '.$trip->status.' tidak dapat dimulai (harus sudah didispatch).',
                ]);
            }

            $odometer = isset($data['start_odometer']) ? (float) $data['start_odometer'] : $trip->start_odometer;
            if ($odometer !== null && $trip->vehicle && (float) $trip->vehicle->odometer > $odometer) {
                throw ValidationException::withMessages([
                    'start_odometer' => 'Odometer awal lebih kecil dari odometer kendaraan terakhir ('.$trip->vehicle->odometer.').',
                ]);
            }

            $trip->forceFill([
                'status' => 'BOARDING',
                'actual_start_at' => $data['started_at'] ?? now(),
                'start_odometer' => $odometer,
            ])->save();

            $this->context->publish('TripStarted', $trip, [
                'trip_number' => $trip->trip_number, 'start_odometer' => $odometer,
            ]);

            return ['started' => true, 'status' => $trip->status, 'started_at' => $trip->actual_start_at, 'start_odometer' => $odometer];
        }, ['source' => 'DRIVER_APP', 'device_id' => $data['device_id'] ?? null]);

        return $result;
    }

    public function arriveStop(Trip $trip, TripStop $stop, array $data = []): array
    {
        if ((int) $stop->trip_id !== (int) $trip->id) {
            throw ValidationException::withMessages(['stop' => 'Halte bukan bagian dari trip ini.']);
        }

        $arrival = isset($data['arrived_at']) ? CarbonImmutable::parse($data['arrived_at']) : CarbonImmutable::now();
        $delay = $stop->planned_arrival_at ? (int) $stop->planned_arrival_at->diffInMinutes($arrival, false) : null;

        $stop->forceFill([
            'status' => 'ARRIVED',
            'actual_arrival_at' => $arrival,
            'arrival_delay_minutes' => $delay,
        ])->save();

        TripStopEvent::query()->create([
            'trip_id' => $trip->id,
            'trip_stop_id' => $stop->id,
            'event_type' => 'ARRIVED',
            'driver_profile_id' => $trip->driver_profile_id,
            'occurred_at' => $arrival,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'source' => $data['source'] ?? 'DRIVER_APP',
            'recorded_by' => $this->context->actorId(),
        ]);

        if (in_array($trip->status, ['DISPATCHED', 'BOARDING'], true)) {
            $trip->forceFill(['status' => 'BOARDING'])->save();
        }

        $this->manifest->updateStopCounters($trip);
        $stop->refresh();

        $this->context->audit('TripStopArrived', $stop, ['trip_number' => $trip->trip_number, 'delay_minutes' => $delay]);

        return [
            'trip_id' => $trip->id,
            'stop_id' => $stop->id,
            'sequence' => $stop->sequence,
            'status' => $stop->status,
            'actual_arrival_at' => $arrival,
            'planned_arrival_at' => $stop->planned_arrival_at,
            'delay_minutes' => $delay,
            'planned_passengers' => $stop->planned_passengers,
        ];
    }

    public function departStop(Trip $trip, TripStop $stop, array $data = []): array
    {
        if ((int) $stop->trip_id !== (int) $trip->id) {
            throw ValidationException::withMessages(['stop' => 'Halte bukan bagian dari trip ini.']);
        }

        $departure = isset($data['departed_at']) ? CarbonImmutable::parse($data['departed_at']) : CarbonImmutable::now();
        $delay = $stop->planned_departure_at ? (int) $stop->planned_departure_at->diffInMinutes($departure, false) : null;

        $stop->forceFill([
            'status' => 'DEPARTED',
            'actual_departure_at' => $departure,
            'departure_delay_minutes' => $delay,
        ])->save();

        $this->manifest->updateStopCounters($trip);

        TripStopEvent::query()->create([
            'trip_id' => $trip->id,
            'trip_stop_id' => $stop->id,
            'event_type' => 'DEPARTED',
            'driver_profile_id' => $trip->driver_profile_id,
            'occurred_at' => $departure,
            'device_id' => $data['device_id'] ?? null,
            'source' => $data['source'] ?? 'DRIVER_APP',
            'recorded_by' => $this->context->actorId(),
        ]);

        $firstStopId = TripStop::query()->where('trip_id', $trip->id)->orderBy('sequence')->value('id');
        if ((int) $stop->id === (int) $firstStopId && $trip->status === 'BOARDING') {
            $trip->forceFill(['status' => 'IN_TRANSIT'])->save();
        }

        $maxDelay = (int) TripStop::query()->where('trip_id', $trip->id)->max('departure_delay_minutes');
        if ($maxDelay > 0) {
            $trip->forceFill(['delay_minutes' => $maxDelay])->save();
        }

        $this->context->audit('TripStopDeparted', $stop, ['trip_number' => $trip->trip_number, 'delay_minutes' => $delay]);

        return [
            'trip_id' => $trip->id,
            'stop_id' => $stop->id,
            'sequence' => $stop->sequence,
            'status' => $stop->status,
            'actual_departure_at' => $departure,
            'delay_minutes' => $delay,
            'next_stop' => optional(TripStop::query()->where('trip_id', $trip->id)
                ->where('sequence', '>', $stop->sequence)->orderBy('sequence')->first())?->only(['id', 'name', 'sequence']),
        ];
    }

    public function reportDelay(Trip $trip, int $minutes, string $reason, ?int $stopId = null, array $options = []): array
    {
        $reason = strtoupper($reason);
        if (! in_array($reason, TransportStatus::delayReasons(), true)) {
            throw ValidationException::withMessages(['reason' => 'Alasan keterlambatan tidak dikenal: '.$reason]);
        }

        $report = TripDelayReport::query()->create([
            'trip_id' => $trip->id,
            'delay_minutes' => $minutes,
            'reason' => $reason,
            'reported_at' => now(),
            'reported_by' => $this->context->actorId(),
            'trip_stop_id' => $stopId,
            'notes' => $options['notes'] ?? null,
        ]);

        $trip->forceFill([
            'delay_minutes' => max((int) $trip->delay_minutes, $minutes),
            'delay_reason' => $reason,
            'status' => in_array($trip->status, ['PLANNED', 'DISPATCHED'], true) ? 'DELAYED' : $trip->status,
        ])->save();

        $this->context->publish('TripDelayed', $trip, [
            'trip_number' => $trip->trip_number, 'minutes' => $minutes, 'reason' => $reason,
        ]);

        $notified = [];
        if ($options['notify'] ?? true) {
            foreach (TripPassenger::query()->where('trip_id', $trip->id)->pluck('student_id')->filter()->unique() as $studentId) {
                $notified[] = $this->notifications->toGuardians('TripDelayed', (int) $studentId,
                    'Perjalanan terlambat '.$minutes.' menit',
                    'Trip '.$trip->trip_number.' diperkirakan terlambat '.$minutes.' menit ('.$reason.').',
                    ['trip_id' => $trip->id, 'idempotency_key' => 'delay-'.$report->id.'-'.$studentId]
                );
            }
            $report->forceFill(['notification_sent' => $notified !== []])->save();
        }

        return [
            'delay_report_id' => $report->id,
            'trip_id' => $trip->id,
            'minutes' => $minutes,
            'reason' => $reason,
            'notifications' => $notified,
        ];
    }

    public function complete(Trip $trip, array $data = [], ?string $idempotencyKey = null): array
    {
        $result = $this->context->idempotent('TRIP_COMPLETE', $idempotencyKey, function () use ($trip, $data): array {
            if ($trip->status === 'COMPLETED') {
                return ['completed' => true, 'already' => true, 'trip_id' => $trip->id];
            }
            if (in_array($trip->status, ['CANCELLED'], true)) {
                throw ValidationException::withMessages(['status' => 'Trip sudah dibatalkan.']);
            }

            $endOdometer = isset($data['end_odometer']) ? (float) $data['end_odometer'] : $trip->end_odometer;
            $startOdometer = $trip->start_odometer !== null ? (float) $trip->start_odometer : null;

            if ($endOdometer !== null && $startOdometer !== null && $endOdometer < $startOdometer) {
                throw ValidationException::withMessages([
                    'end_odometer' => 'Odometer akhir ('.$endOdometer.') lebih kecil dari odometer awal ('.$startOdometer.').',
                ]);
            }

            $distance = ($endOdometer !== null && $startOdometer !== null)
                ? round($endOdometer - $startOdometer, 2)
                : ($trip->route?->distance_km !== null ? (float) $trip->route->distance_km : null);

            $trip->forceFill([
                'status' => 'COMPLETED',
                'actual_end_at' => $data['ended_at'] ?? now(),
                'end_odometer' => $endOdometer,
                'distance_km' => $distance,
            ])->save();

            $manifest = $this->manifest->generate($trip, ['rebuild' => false]);
            $manifest->forceFill(['status' => 'CLOSED', 'finalized_at' => now()])->save();

            if ($trip->vehicle) {
                $trip->vehicle->forceFill([
                    'status' => $trip->vehicle->status === 'MAINTENANCE' ? 'MAINTENANCE' : 'AVAILABLE',
                    'odometer' => $endOdometer ?? $trip->vehicle->odometer,
                ])->save();
            }
            if ($trip->driver) {
                $trip->driver->forceFill(['driver_status' => 'AVAILABLE'])->save();
            }

            $this->context->publish('TripCompleted', $trip, [
                'trip_number' => $trip->trip_number,
                'distance_km' => $distance,
                'boarded' => $manifest->boarded_passengers,
                'no_show' => $manifest->no_show_passengers,
            ]);

            return [
                'completed' => true,
                'trip_id' => $trip->id,
                'distance_km' => $distance,
                'manifest' => $manifest->only(['expected_passengers', 'boarded_passengers', 'dropped_off_passengers',
                    'no_show_passengers', 'occupancy_percent']),
            ];
        }, ['source' => 'DRIVER_APP', 'device_id' => $data['device_id'] ?? null]);

        return $result;
    }

    public function cancel(Trip $trip, string $reason, array $options = []): array
    {
        if ($trip->status === 'COMPLETED') {
            throw ValidationException::withMessages(['status' => 'Trip yang sudah selesai tidak dapat dibatalkan (gunakan adjustment).']);
        }

        $trip->forceFill([
            'status' => 'CANCELLED',
            'cancellation_reason' => $reason,
            'cancelled_by' => $options['cancelled_by'] ?? $this->context->actorId(),
            'cancelled_at' => now(),
        ])->save();

        if ($trip->vehicle && $trip->vehicle->status === 'ON_TRIP') {
            $trip->vehicle->forceFill(['status' => 'AVAILABLE'])->save();
        }
        if ($trip->driver && $trip->driver->driver_status === 'ON_TRIP') {
            $trip->driver->forceFill(['driver_status' => 'AVAILABLE'])->save();
        }

        $this->context->publish('TripCancelled', $trip, ['trip_number' => $trip->trip_number, 'reason' => $reason]);

        $notifications = [];
        if ($options['notify'] ?? true) {
            foreach (TripPassenger::query()->where('trip_id', $trip->id)->pluck('student_id')->filter()->unique() as $studentId) {
                $notifications[] = $this->notifications->toGuardians('TripCancelled', (int) $studentId,
                    'Perjalanan dibatalkan',
                    'Trip '.$trip->trip_number.' dibatalkan. Alasan: '.$reason.'.',
                    ['trip_id' => $trip->id, 'idempotency_key' => 'cancel-'.$trip->id.'-'.$studentId]
                );
            }
            $trip->forceFill(['notification_status' => $notifications !== [] ? 'SENT' : 'SKIPPED'])->save();
        }

        return [
            'cancelled' => true,
            'trip_id' => $trip->id,
            'reason' => $reason,
            'cancelled_at' => $trip->cancelled_at,
            'notifications' => $notifications,
        ];
    }

    public function markIncident(Trip $trip, ?string $note = null): Trip
    {
        $trip->forceFill(['status' => 'INCIDENT'])->save();
        $this->context->audit('TripMarkedIncident', $trip, ['note' => $note]);

        return $trip->refresh();
    }

    /** Perubahan hanya boleh untuk trip yang belum berjalan (§87 Rule 12). */
    public function updateTrip(Trip $trip, array $payload): Trip
    {
        if (! in_array($trip->status, ['PLANNED', 'VEHICLE_ASSIGNED', 'DRIVER_ASSIGNED'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Trip berstatus '.$trip->status.' tidak dapat diubah langsung; gunakan penyesuaian (adjustment) yang diaudit.',
            ]);
        }

        $trip->fill(array_intersect_key($payload, array_flip([
            'planned_departure', 'planned_arrival', 'direction', 'trip_type', 'vehicle_id', 'driver_profile_id',
            'event_reference_id', 'event_reference_type', 'meta',
        ])))->save();

        $this->context->audit('TripUpdated', $trip, ['changes' => array_keys($payload)]);

        return $trip->refresh();
    }

    // ── Tampilan operasional ───────────────────────────────────────────────

    public function tripSnapshot(Trip $trip): array
    {
        $trip->loadMissing(['route', 'vehicle', 'driver']);
        $manifest = TripManifest::query()->where('trip_id', $trip->id)->first();

        return [
            'id' => $trip->id,
            'trip_number' => $trip->trip_number,
            'trip_date' => $trip->trip_date?->toDateString(),
            'direction' => $trip->direction,
            'trip_type' => $trip->trip_type,
            'status' => $trip->status,
            'is_ad_hoc' => (bool) $trip->is_ad_hoc,
            'route' => $trip->route ? [
                'id' => $trip->route->id, 'code' => $trip->route->code, 'name' => $trip->route->name,
                'origin' => $trip->route->origin, 'destination' => $trip->route->destination,
            ] : null,
            'vehicle' => $trip->vehicle ? [
                'id' => $trip->vehicle->id, 'vehicle_code' => $trip->vehicle->vehicle_code,
                'registration_number' => $trip->vehicle->registration_number,
                'capacity' => (int) $trip->vehicle->student_capacity,
            ] : null,
            'driver' => $trip->driver ? [
                'id' => $trip->driver->id, 'driver_code' => $trip->driver->driver_code,
                'full_name' => $trip->driver->full_name, 'phone' => $trip->driver->phone,
            ] : null,
            'planned' => [
                'departure' => $trip->planned_departure,
                'arrival' => $trip->planned_arrival,
                'start_at' => $trip->planned_start_at,
                'end_at' => $trip->planned_end_at,
            ],
            'actual' => [
                'start_at' => $trip->actual_start_at,
                'end_at' => $trip->actual_end_at,
                'start_odometer' => $trip->start_odometer !== null ? (float) $trip->start_odometer : null,
                'end_odometer' => $trip->end_odometer !== null ? (float) $trip->end_odometer : null,
                'distance_km' => $trip->distance_km !== null ? (float) $trip->distance_km : null,
                'delay_minutes' => (int) $trip->delay_minutes,
                'delay_reason' => $trip->delay_reason,
            ],
            'counts' => [
                'planned' => $manifest?->expected_passengers ?? 0,
                'boarded' => $manifest?->boarded_passengers ?? 0,
                'dropped_off' => $manifest?->dropped_off_passengers ?? 0,
                'no_show' => $manifest?->no_show_passengers ?? 0,
                'occupancy_percent' => $manifest?->occupancy_percent ?? 0,
            ],
            'stops' => TripStop::query()->where('trip_id', $trip->id)->orderBy('sequence')->get()->map(
                fn (TripStop $stop): array => [
                    'id' => $stop->id,
                    'sequence' => $stop->sequence,
                    'name' => $stop->name,
                    'status' => $stop->status,
                    'planned_arrival_at' => $stop->planned_arrival_at,
                    'actual_arrival_at' => $stop->actual_arrival_at,
                    'actual_departure_at' => $stop->actual_departure_at,
                    'arrival_delay_minutes' => $stop->arrival_delay_minutes,
                    'planned_passengers' => $stop->planned_passengers,
                    'boarded_count' => $stop->boarded_count,
                    'dropped_off_count' => $stop->dropped_off_count,
                ]
            )->all(),
            'inspection' => $this->inspections->summary($trip),
            'cancellation' => $trip->cancelled_at ? [
                'reason' => $trip->cancellation_reason,
                'at' => $trip->cancelled_at,
                'notification_status' => $trip->notification_status,
            ] : null,
        ];
    }

    public function todayTrips(array $filters = []): array
    {
        $date = $filters['date'] ?? now()->toDateString();

        return Trip::query()
            ->whereDate('trip_date', $date)
            ->when($filters['campus_id'] ?? null, fn ($q, $campus) => $q->where('campus_id', $campus))
            ->when($filters['route_id'] ?? null, fn ($q, $routeId) => $q->where('route_id', $routeId))
            ->when($filters['vehicle_id'] ?? null, fn ($q, $vehicleId) => $q->where('vehicle_id', $vehicleId))
            ->when($filters['driver_profile_id'] ?? null, fn ($q, $driverId) => $q->where('driver_profile_id', $driverId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->with(['route', 'vehicle', 'driver'])
            ->orderBy('planned_departure')
            ->get()
            ->map(fn (Trip $trip): array => $this->tripSnapshot($trip))
            ->all();
    }

    /** Papan dispatch real-time (§76/§96). */
    public function dispatchBoard(array $filters = []): array
    {
        $date = $filters['date'] ?? now()->toDateString();
        $trips = $this->todayTrips($filters + ['date' => $date]);

        $passengerStats = DB::table('trip_passengers')
            ->join('trips', 'trips.id', '=', 'trip_passengers.trip_id')
            ->where('trips.trip_date', $date)
            ->selectRaw('count(*) as expected')
            ->selectRaw("sum(case when boarding_status = 'BOARDED' then 1 else 0 end) as boarded")
            ->selectRaw("sum(case when boarding_status = 'NO_SHOW' then 1 else 0 end) as no_show")
            ->selectRaw("sum(case when boarding_status = 'EXPECTED' then 1 else 0 end) as pending")
            ->first();

        $rows = array_map(function (array $trip): array {
            $status = match ($trip['status']) {
                'DELAYED' => 'DELAY '.$trip['actual']['delay_minutes'].'m',
                'INCIDENT' => 'INCIDENT',
                'CANCELLED' => 'CANCELLED',
                'COMPLETED' => 'COMPLETED',
                'IN_TRANSIT', 'BOARDING' => 'IN PROGRESS',
                'DISPATCHED' => 'ON TIME',
                default => $trip['status'],
            };

            return [
                'trip_id' => $trip['id'],
                'trip_number' => $trip['trip_number'],
                'departure' => $trip['planned']['departure'],
                'route' => $trip['route']['name'] ?? '-',
                'route_code' => $trip['route']['code'] ?? '-',
                'driver' => $trip['driver']['full_name'] ?? 'Belum ditugaskan',
                'vehicle' => $trip['vehicle']['vehicle_code'] ?? 'Belum ditugaskan',
                'status' => $trip['status'],
                'status_label' => $status,
                'delay_minutes' => $trip['actual']['delay_minutes'],
                'passengers' => $trip['counts'],
            ];
        }, $trips);

        return [
            'date' => $date,
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'trips' => count($trips),
                'on_time' => count(array_filter($rows, fn (array $row): bool => $row['status_label'] === 'ON TIME')),
                'delayed' => count(array_filter($rows, fn (array $row): bool => str_starts_with($row['status_label'], 'DELAY'))),
                'incidents' => count(array_filter($rows, fn (array $row): bool => $row['status_label'] === 'INCIDENT')),
                'cancelled' => count(array_filter($rows, fn (array $row): bool => $row['status_label'] === 'CANCELLED')),
                'completed' => count(array_filter($rows, fn (array $row): bool => $row['status_label'] === 'COMPLETED')),
                'expected_passengers' => (int) ($passengerStats->expected ?? 0),
                'boarded' => (int) ($passengerStats->boarded ?? 0),
                'no_show' => (int) ($passengerStats->no_show ?? 0),
                'pending' => (int) ($passengerStats->pending ?? 0),
            ],
            'rows' => $rows,
        ];
    }

    public function history(array $filters = []): array
    {
        return Trip::query()
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('trip_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('trip_date', '<=', $to))
            ->when($filters['route_id'] ?? null, fn ($q, $routeId) => $q->where('route_id', $routeId))
            ->when($filters['vehicle_id'] ?? null, fn ($q, $vehicleId) => $q->where('vehicle_id', $vehicleId))
            ->when($filters['driver_profile_id'] ?? null, fn ($q, $driverId) => $q->where('driver_profile_id', $driverId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->orderByDesc('trip_date')->orderByDesc('planned_departure')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get()
            ->map(fn (Trip $trip): array => [
                'id' => $trip->id,
                'trip_number' => $trip->trip_number,
                'trip_date' => $trip->trip_date->toDateString(),
                'route_id' => $trip->route_id,
                'vehicle_id' => $trip->vehicle_id,
                'driver_profile_id' => $trip->driver_profile_id,
                'status' => $trip->status,
                'distance_km' => $trip->distance_km !== null ? (float) $trip->distance_km : null,
                'delay_minutes' => (int) $trip->delay_minutes,
                'boarded_count' => (int) $trip->boarded_count,
            ])
            ->all();
    }
}
