<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\LocationGatewayInterface;
use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\{
    TransportationRoute, RouteVersion, RouteStop, RouteStopVersion, TransportationAssignment,
    TransportationSchedule, Trip
};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rute (§16-§21): rute sebagai entitas inti, versi rute yang tidak destruktif,
 * halte berurutan dengan geolokasi siap GPS, kapasitas, dan batasan rute.
 */
class RouteService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly LocationGatewayInterface $location,
    ) {
    }

    /** Buat rute beserta versi 1 dan halte awalnya. */
    public function createRoute(array $payload, ?int $actorId = null): TransportationRoute
    {
        $type = strtoupper((string) ($payload['route_type'] ?? 'PICKUP'));
        if (! in_array($type, (array) config('transportation.route_types', []), true)) {
            throw ValidationException::withMessages(['route_type' => 'Jenis rute tidak dikenal: '.$type]);
        }

        $route = TransportationRoute::query()->create([
            'code' => $payload['code'] ?? ('ROUTE-'.str_pad((string) (TransportationRoute::query()->count() + 1), 3, '0', STR_PAD_LEFT)),
            'name' => $payload['name'] ?? 'Rute Baru',
            'route_type' => $type,
            'campus_id' => $payload['campus_id'] ?? null,
            'service_id' => $payload['service_id'] ?? null,
            'origin' => $payload['origin'] ?? null,
            'destination' => $payload['destination'] ?? null,
            'distance_km' => $payload['distance_km'] ?? null,
            'estimated_duration_minutes' => $payload['estimated_duration_minutes'] ?? null,
            'default_vehicle_capacity' => $payload['default_vehicle_capacity'] ?? config('transportation.planning.default_vehicle_capacity'),
            'max_stops' => $payload['max_stops'] ?? config('transportation.planning.max_stops_per_route'),
            'max_duration_minutes' => $payload['max_duration_minutes'] ?? config('transportation.planning.max_route_duration_minutes'),
            'pickup_window_start' => $payload['pickup_window_start'] ?? null,
            'pickup_window_end' => $payload['pickup_window_end'] ?? null,
            'dropoff_window_start' => $payload['dropoff_window_start'] ?? null,
            'dropoff_window_end' => $payload['dropoff_window_end'] ?? null,
            'school_arrival_deadline' => $payload['school_arrival_deadline'] ?? null,
            'school_departure_deadline' => $payload['school_departure_deadline'] ?? null,
            'status' => strtoupper((string) ($payload['status'] ?? 'DRAFT')),
            'active' => (bool) ($payload['active'] ?? true),
            'notes' => $payload['notes'] ?? null,
        ]);

        $version = RouteVersion::query()->create([
            'route_id' => $route->id,
            'version_number' => 1,
            'effective_from' => $payload['effective_from'] ?? now()->toDateString(),
            'status' => 'ACTIVE',
            'change_summary' => 'Versi awal rute.',
            'created_by' => $actorId ?? $this->context->actorId(),
            'approved_by' => $actorId ?? $this->context->actorId(),
            'approved_at' => now(),
        ]);

        $route->forceFill(['current_version_id' => $version->id, 'version_number' => 1])->save();

        if (! empty($payload['stops'])) {
            foreach (array_values($payload['stops']) as $index => $stop) {
                $this->addStop($route, $stop + ['sequence' => $stop['sequence'] ?? ($index + 1)], (int) $version->id);
            }
        }

        $this->context->audit('RouteCreated', $route, ['code' => $route->code, 'type' => $type]);

        return $route->refresh();
    }

    /**
     * Perbarui rute tanpa menghapus versi lama (§18).
     * Perubahan parameter membuat versi baru; halte lama tetap terekam.
     */
    public function updateRoute(TransportationRoute $route, array $payload, ?string $changeSummary = null, ?int $actorId = null): RouteVersion
    {
        $before = $route->only(['name', 'route_type', 'distance_km', 'estimated_duration_minutes',
            'pickup_window_start', 'dropoff_window_start', 'school_arrival_deadline', 'school_departure_deadline']);

        $route->fill(array_intersect_key($payload, array_flip([
            'name', 'route_type', 'campus_id', 'service_id', 'origin', 'destination', 'distance_km',
            'estimated_duration_minutes', 'default_vehicle_capacity', 'max_stops', 'max_duration_minutes',
            'pickup_window_start', 'pickup_window_end', 'dropoff_window_start', 'dropoff_window_end',
            'school_arrival_deadline', 'school_departure_deadline', 'notes', 'status', 'active',
        ])))->save();

        if (! empty($payload['stops'])) {
            $this->snapshotVersion($route, $changeSummary ?? 'Perubahan halte rute.', $actorId);
            foreach (array_values($payload['stops']) as $index => $stop) {
                $this->addStop($route, $stop + ['sequence' => $stop['sequence'] ?? ($index + 1)], (int) $route->current_version_id);
            }
        } else {
            $this->snapshotVersion($route, $changeSummary ?? 'Perubahan parameter rute.', $actorId);
        }

        $this->context->audit('RouteUpdated', $route, ['before' => $before, 'after' => $route->only(array_keys($before))]);

        return RouteVersion::query()->findOrFail($route->current_version_id);
    }

    /** Bekukan kondisi saat ini menjadi versi baru (non-destruktif). */
    public function snapshotVersion(TransportationRoute $route, string $changeSummary, ?int $actorId = null, ?string $effectiveFrom = null): RouteVersion
    {
        $previous = RouteVersion::query()->where('route_id', $route->id)->orderByDesc('version_number')->first();
        $nextNumber = ((int) ($previous->version_number ?? 0)) + 1;

        $version = RouteVersion::query()->create([
            'route_id' => $route->id,
            'version_number' => $nextNumber,
            'effective_from' => $effectiveFrom ?? now()->toDateString(),
            'status' => 'ACTIVE',
            'distance_km' => $route->distance_km,
            'estimated_duration_minutes' => $route->estimated_duration_minutes,
            'change_summary' => $changeSummary,
            'snapshot' => $this->versionSnapshot($route),
            'created_by' => $actorId ?? $this->context->actorId(),
            'approved_by' => $actorId ?? $this->context->actorId(),
            'approved_at' => now(),
        ]);

        if ($previous) {
            $previous->forceFill([
                'status' => 'SUPERSEDED',
                'effective_until' => now()->subDay()->toDateString(),
            ])->save();
        }

        foreach (RouteStop::query()->where('route_id', $route->id)->orderBy('sequence')->get() as $stop) {
            RouteStopVersion::query()->create([
                'route_version_id' => $version->id,
                'route_stop_id' => $stop->id,
                'sequence' => $stop->sequence,
                'attributes' => $stop->only(['name', 'address', 'latitude', 'longitude', 'planned_arrival',
                    'planned_departure', 'pickup_allowed', 'dropoff_allowed', 'capacity', 'stop_type']),
                'change_type' => 'SNAPSHOT',
            ]);
        }

        $route->forceFill(['current_version_id' => $version->id, 'version_number' => $nextNumber])->save();

        $this->context->audit('RouteVersionCreated', $version, ['version' => $nextNumber, 'summary' => $changeSummary]);
        $this->context->publish('RouteChanged', $route, ['version' => $nextNumber, 'summary' => $changeSummary]);

        return $version;
    }

    public function versionSnapshot(TransportationRoute $route): array
    {
        $stops = RouteStop::query()->where('route_id', $route->id)->orderBy('sequence')->get();

        return [
            'code' => $route->code,
            'name' => $route->name,
            'route_type' => $route->route_type,
            'distance_km' => $route->distance_km,
            'estimated_duration_minutes' => $route->estimated_duration_minutes,
            'stops' => $stops->map(fn (RouteStop $stop): array => [
                'sequence' => $stop->sequence,
                'name' => $stop->name,
                'latitude' => $stop->latitude,
                'longitude' => $stop->longitude,
                'planned_arrival' => $stop->planned_arrival,
                'planned_departure' => $stop->planned_departure,
            ])->all(),
        ];
    }

    public function approveVersion(RouteVersion $version, int $actorId): RouteVersion
    {
        $version->forceFill([
            'status' => 'ACTIVE',
            'approved_by' => $actorId,
            'approved_at' => now(),
        ])->save();

        $this->context->audit('RouteVersionApproved', $version, ['version' => $version->version_number]);

        return $version->refresh();
    }

    // ── Halte (§19-§21) ────────────────────────────────────────────────────

    public function addStop(TransportationRoute $route, array $payload, ?int $routeVersionId = null): RouteStop
    {
        $sequence = (int) ($payload['sequence'] ?? ((int) RouteStop::query()->where('route_id', $route->id)->max('sequence') + 1));
        $type = strtoupper((string) ($payload['stop_type'] ?? 'PICKUP_POINT'));

        $maxStops = (int) ($route->max_stops ?? config('transportation.planning.max_stops_per_route'));
        $existing = RouteStop::query()->where('route_id', $route->id)->count();
        if ($existing >= $maxStops) {
            throw ValidationException::withMessages([
                'sequence' => 'Jumlah halte melebihi batas rute ('.$maxStops.').',
            ]);
        }

        $stop = RouteStop::query()->create([
            'route_id' => $route->id,
            'route_version_id' => $routeVersionId ?? $route->current_version_id,
            'sequence' => $sequence,
            'name' => $payload['name'] ?? ('Halte '.$sequence),
            'stop_type' => $type,
            'address' => $payload['address'] ?? null,
            'location_id' => $payload['location_id'] ?? null,
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'geofence_radius_meters' => (int) ($payload['geofence_radius_meters'] ?? 100),
            'planned_arrival' => $payload['planned_arrival'] ?? null,
            'planned_departure' => $payload['planned_departure'] ?? null,
            'pickup_allowed' => (bool) ($payload['pickup_allowed'] ?? true),
            'dropoff_allowed' => (bool) ($payload['dropoff_allowed'] ?? true),
            'capacity' => $payload['capacity'] ?? null,
            'default_dwell_minutes' => $payload['default_dwell_minutes'] ?? 3,
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->recomputeRouteMetrics($route);
        $this->context->audit('RouteStopAdded', $stop, ['route_id' => $route->id, 'sequence' => $sequence]);

        return $stop;
    }

    public function updateStop(RouteStop $stop, array $payload): RouteStop
    {
        $before = $stop->toArray();
        $stop->fill(array_intersect_key($payload, array_flip([
            'name', 'stop_type', 'address', 'latitude', 'longitude', 'geofence_radius_meters',
            'planned_arrival', 'planned_departure', 'pickup_allowed', 'dropoff_allowed', 'capacity', 'notes',
        ])))->save();

        $this->recomputeRouteMetrics(TransportationRoute::query()->findOrFail($stop->route_id));
        $this->context->audit('RouteStopUpdated', $stop, [
            'before' => array_intersect_key($before, $payload), 'after' => $stop->only(array_keys($payload)),
        ]);

        return $stop->refresh();
    }

    public function removeStop(RouteStop $stop, ?int $actorId = null, ?string $reason = null): array
    {
        $assignmentCount = TransportationAssignment::query()
            ->where('pickup_stop_id', $stop->id)->orWhere('dropoff_stop_id', $stop->id)
            ->where('status', 'ACTIVE')->count();

        $stop->delete();
        $this->context->audit('RouteStopRemoved', $stop, ['reason' => $reason, 'affected_assignments' => $assignmentCount]);

        return ['removed' => true, 'affected_assignments' => $assignmentCount];
    }

    public function stops(int $routeId, ?int $versionId = null): array
    {
        return RouteStop::query()
            ->where('route_id', $routeId)
            ->when($versionId, fn ($q) => $q->where('route_version_id', $versionId))
            ->orderBy('sequence')
            ->get()
            ->map(fn (RouteStop $stop): array => $stop->toArray())
            ->all();
    }

    /** Apakah halte benar-benar milik rute aktif (§87 Rule 7)? */
    public function stopBelongs(?int $routeId, ?int $stopId): bool
    {
        if (! $routeId || ! $stopId) {
            return false;
        }

        return RouteStop::query()->where('id', $stopId)->where('route_id', $routeId)->exists();
    }

    private function recomputeRouteMetrics(TransportationRoute $route): void
    {
        $stops = RouteStop::query()->where('route_id', $route->id)->orderBy('sequence')->get();
        $distance = 0.0;
        $previous = null;
        foreach ($stops as $stop) {
            if ($previous && $previous->latitude !== null && $stop->latitude !== null) {
                $segment = $this->location->distanceKm(
                    ['latitude' => $previous->latitude, 'longitude' => $previous->longitude],
                    ['latitude' => $stop->latitude, 'longitude' => $stop->longitude]
                );
                $distance += (float) ($segment ?? 0);
            }
            $previous = $stop;
        }

        $first = $stops->first();
        $last = $stops->last();
        $duration = null;
        if ($first?->planned_departure && $last?->planned_arrival) {
            $duration = $this->minutesBetween((string) $first->planned_departure, (string) $last->planned_arrival);
        }

        $route->forceFill([
            'distance_km' => $distance > 0 ? round($distance, 2) : $route->distance_km,
            'estimated_duration_minutes' => $duration ?? $route->estimated_duration_minutes,
        ])->save();
    }

    private function minutesBetween(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', substr($start, 0, 5)));
        [$eh, $em] = array_map('intval', explode(':', substr($end, 0, 5)));
        $minutes = ($eh * 60 + $em) - ($sh * 60 + $sm);

        return $minutes >= 0 ? $minutes : $minutes + 1440;
    }

    // ── Kapasitas rute (§27) ───────────────────────────────────────────────

    public function routeCapacity(int $routeId, ?string $date = null): array
    {
        $route = TransportationRoute::query()->findOrFail($routeId);
        $date ??= now()->toDateString();

        $schedule = TransportationSchedule::query()->where('route_id', $routeId)->where('active', true)->first();
        $vehicle = $schedule?->defaultVehicle;
        $capacity = $vehicle
            ? max(0, (int) $vehicle->student_capacity - (int) $vehicle->reserved_capacity)
            : (int) ($route->default_vehicle_capacity ?? config('transportation.planning.default_vehicle_capacity'));

        $assigned = TransportationAssignment::query()
            ->where('route_id', $routeId)
            ->where('status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->count();

        return [
            'route_id' => $routeId,
            'route_code' => $route->code,
            'route_name' => $route->name,
            'date' => $date,
            'vehicle_id' => $vehicle?->id,
            'vehicle_code' => $vehicle?->vehicle_code,
            'capacity' => $capacity,
            'assigned' => $assigned,
            'available_seats' => max(0, $capacity - $assigned),
            'occupancy_percent' => $capacity > 0 ? round($assigned / $capacity * 100, 2) : 0.0,
            'is_full' => $assigned >= $capacity,
            'waitlist_count' => DB::table('transportation_waitlists')
                ->where('route_id', $routeId)->where('status', 'WAITING')->count(),
        ];
    }

    public function activeRoutes(array $filters = []): array
    {
        return TransportationRoute::query()
            ->where('active', true)
            ->when($filters['campus_id'] ?? null, fn ($q, $campus) => $q->where('campus_id', $campus))
            ->when($filters['route_type'] ?? null, fn ($q, $type) => $q->where('route_type', strtoupper($type)))
            ->when($filters['service_id'] ?? null, fn ($q, $service) => $q->where('service_id', $service))
            ->orderBy('code')
            ->get()
            ->map(fn (TransportationRoute $route): array => [
                'id' => $route->id,
                'code' => $route->code,
                'name' => $route->name,
                'route_type' => $route->route_type,
                'campus_id' => $route->campus_id,
                'origin' => $route->origin,
                'destination' => $route->destination,
                'distance_km' => $route->distance_km !== null ? (float) $route->distance_km : null,
                'estimated_duration_minutes' => $route->estimated_duration_minutes,
                'version_number' => $route->version_number,
                'status' => $route->status,
                'stop_count' => RouteStop::query()->where('route_id', $route->id)->count(),
            ])
            ->all();
    }

    /**
     * §67 batasan rute: durasi maksimum, jumlah halte, jendela waktu,
     * tenggat kedatangan sekolah, kapasitas kendaraan.
     */
    public function validateConstraints(TransportationRoute $route, array $context = []): array
    {
        $violations = [];
        $stops = RouteStop::query()->where('route_id', $route->id)->orderBy('sequence')->get();
        $maxStops = (int) ($route->max_stops ?? config('transportation.planning.max_stops_per_route'));

        if ($stops->count() > $maxStops) {
            $violations[] = ['code' => 'MAX_STOPS', 'message' => 'Jumlah halte '.$stops->count().' melebihi batas '.$maxStops.'.'];
        }

        $duration = (int) ($route->estimated_duration_minutes ?? 0);
        $maxDuration = (int) ($route->max_duration_minutes ?? config('transportation.planning.max_route_duration_minutes'));
        if ($duration > $maxDuration) {
            $violations[] = ['code' => 'MAX_DURATION', 'message' => 'Durasi rute '.$duration.' menit melebihi batas '.$maxDuration.' menit.'];
        }

        if ($route->pickup_window_start && $route->school_arrival_deadline) {
            $available = $this->minutesBetween((string) $route->pickup_window_start, (string) $route->school_arrival_deadline);
            if ($duration > $available) {
                $violations[] = [
                    'code' => 'ARRIVAL_DEADLINE',
                    'message' => 'Durasi rute melebihi jendela penjemputan hingga tenggat kedatangan sekolah.',
                ];
            }
        }

        if (! empty($context['passengers']) && ! empty($context['vehicle_capacity'])
            && (int) $context['passengers'] > (int) $context['vehicle_capacity']) {
            $violations[] = [
                'code' => 'CAPACITY',
                'message' => 'Penumpang '.$context['passengers'].' melebihi kapasitas kendaraan '.$context['vehicle_capacity'].'.',
            ];
        }

        if (isset($context['driver_working_minutes']) && $context['driver_working_minutes'] > 480) {
            $violations[] = ['code' => 'DRIVER_HOURS', 'message' => 'Waktu kerja pengemudi melebihi 8 jam pada hari ini.'];
        }

        return [
            'valid' => $violations === [],
            'route_id' => $route->id,
            'violations' => $violations,
            'checked' => ['max_stops' => $maxStops, 'max_duration_minutes' => $maxDuration, 'stop_count' => $stops->count()],
        ];
    }

    /** Data siap-optimasi untuk fase lanjutan (§66). */
    public function optimizationData(int $routeId, ?string $date = null): array
    {
        $route = TransportationRoute::query()->findOrFail($routeId);
        $stops = RouteStop::query()->where('route_id', $routeId)->orderBy('sequence')->get();
        $capacity = $this->routeCapacity($routeId, $date);

        $passengerCounts = TransportationAssignment::query()
            ->where('route_id', $routeId)->where('status', 'ACTIVE')
            ->select('pickup_stop_id', DB::raw('count(*) as total'))
            ->groupBy('pickup_stop_id')
            ->pluck('total', 'pickup_stop_id')
            ->all();

        return [
            'route_id' => $routeId,
            'stops' => $stops->map(fn (RouteStop $stop): array => [
                'id' => $stop->id,
                'sequence' => $stop->sequence,
                'name' => $stop->name,
                'latitude' => $stop->latitude,
                'longitude' => $stop->longitude,
                'planned_arrival' => $stop->planned_arrival,
                'planned_departure' => $stop->planned_departure,
                'passengers' => (int) ($passengerCounts[$stop->id] ?? 0),
                'capacity' => $stop->capacity,
            ])->all(),
            'constraints' => [
                'vehicle_capacity' => $capacity['capacity'],
                'max_duration_minutes' => $route->max_duration_minutes,
                'max_stops' => $route->max_stops,
                'pickup_window' => [$route->pickup_window_start, $route->pickup_window_end],
                'dropoff_window' => [$route->dropoff_window_start, $route->dropoff_window_end],
                'school_arrival_deadline' => $route->school_arrival_deadline,
                'school_departure_deadline' => $route->school_departure_deadline,
            ],
            'distance_km' => $route->distance_km !== null ? (float) $route->distance_km : null,
            'duration_minutes' => $route->estimated_duration_minutes,
        ];
    }
}
