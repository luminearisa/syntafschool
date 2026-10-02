<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\{Trip, VehicleProfile};
use Illuminate\Support\Facades\DB;

/**
 * Perencanaan kapasitas (§64/§65) & permintaan transportasi kegiatan.
 * Output berupa kebutuhan kendaraan/trip/pengemudi — bukan klaim optimasi rute.
 */
class CapacityPlanningService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly TripService $trips,
        private readonly RouteService $routes,
        private readonly FleetService $fleet,
    ) {
    }

    /** §65: 120 penumpang, 40 kursi/bus → 3 bus. */
    public function planRequirements(array $payload): array
    {
        $passengers = (int) ($payload['passengers'] ?? 0);
        if ($passengers <= 0) {
            return ['valid' => false, 'reason' => 'Jumlah penumpang harus lebih dari nol.'];
        }

        $capacity = (int) ($payload['vehicle_capacity']
            ?? $this->availableCapacity($payload)
            ?? config('transportation.planning.default_vehicle_capacity'));

        if ($capacity <= 0) {
            return ['valid' => false, 'reason' => 'Kapasitas kendaraan tidak dapat ditentukan.'];
        }

        $vehicles = (int) ceil($passengers / $capacity);
        $tripsPerVehicle = (int) ($payload['trips_per_vehicle'] ?? 1);
        $totalTrips = (int) ceil($vehicles / max(1, $tripsPerVehicle));
        $drivers = (int) ($payload['drivers_per_vehicle'] ?? 1) * $vehicles;
        $duration = (int) ($payload['duration_minutes'] ?? 60);

        return [
            'valid' => true,
            'passengers' => $passengers,
            'vehicle_capacity' => $capacity,
            'required_vehicles' => $vehicles,
            'required_trips' => $totalTrips,
            'required_drivers' => $drivers,
            'per_vehicle_assignment' => array_fill(0, $vehicles, min($capacity, $passengers)),
            'estimated_duration_minutes' => $duration,
            'estimated_cost' => $this->estimateCost($vehicles, $passengers, $payload),
            'available_vehicles' => count($this->availableVehiclesFor($payload)),
            'feasible' => count($this->availableVehiclesFor($payload)) >= $vehicles,
            'explanation' => sprintf('%d penumpang ÷ %d kursi per kendaraan = %d kendaraan.', $passengers, $capacity, $vehicles),
        ];
    }

    /** §64: rencana transportasi kegiatan (lomba, field trip) + kebutuhan armada. */
    public function planEventTransport(array $payload): array
    {
        $plan = $this->planRequirements($payload);
        if (! ($plan['valid'] ?? false)) {
            return $plan;
        }

        $fleet = $this->availableVehiclesFor($payload);
        $recommended = array_slice($fleet, 0, $plan['required_vehicles']);
        $driversAvailable = DB::table('driver_profiles')
            ->whereNotIn('driver_status', ['OFF_DUTY', 'LEAVE', 'SUSPENDED', 'INACTIVE'])
            ->count();

        return $plan + [
            'event' => [
                'name' => $payload['event_name'] ?? null,
                'date' => $payload['date'] ?? null,
                'departure_time' => $payload['departure_time'] ?? null,
                'location' => $payload['location'] ?? null,
            ],
            'vehicles' => array_map(fn (array $vehicle): array => [
                'id' => $vehicle['id'],
                'vehicle_code' => $vehicle['vehicle_code'],
                'capacity' => $vehicle['capacity']['usable_capacity'],
                'status' => $vehicle['status'],
            ], $recommended),
            'drivers_available' => $driversAvailable,
            'driver_requirement_met' => $driversAvailable >= $plan['required_drivers'],
            'route_options' => $this->routes->activeRoutes(['route_type' => $payload['route_type'] ?? null]),
            'passenger_list_expected' => (bool) ($payload['passenger_list'] ?? false),
        ];
    }

    /** Buat usulan trip kegiatan (belum dijalankan) untuk ditinjau (§55/§64). */
    public function createEventTrips(array $payload): array
    {
        $plan = $this->planEventTransport($payload);
        if (! ($plan['valid'] ?? false)) {
            return ['created' => [], 'reason' => $plan['reason'] ?? 'Rencana tidak valid.'];
        }

        $created = [];
        foreach ($plan['vehicles'] as $index => $vehicle) {
            $trip = $this->trips->createAdHoc([
                'route_id' => $payload['route_id'],
                'trip_date' => $payload['date'] ?? now()->toDateString(),
                'planned_departure' => $payload['departure_time'] ?? '06:00',
                'planned_arrival' => $payload['arrival_time'] ?? ($payload['departure_time'] ?? '06:00'),
                'direction' => 'PICKUP',
                'trip_type' => strtoupper((string) ($payload['trip_type'] ?? 'EVENT')),
                'service_id' => $payload['service_id'] ?? null,
                'event_reference_id' => $payload['event_reference_id'] ?? null,
                'event_reference_type' => $payload['event_reference_type'] ?? null,
                'planned_passengers' => min($plan['vehicle_capacity'], (int) $plan['passengers']),
                'passengers' => $payload['passengers'][$index]['list'] ?? [],
            ]);
            $created[] = $trip->only(['id', 'trip_number', 'trip_date', 'planned_passengers', 'trip_type']);
        }

        $this->context->audit('EventTransportPlanned', null, [
            'trips' => count($created), 'passengers' => $plan['passengers'], 'vehicles' => $plan['required_vehicles'],
        ]);

        return ['created' => $created, 'plan' => $plan, 'trips_created' => count($created)];
    }

    /** Ikhtisar kapasitas seluruh rute (§27/§75). */
    public function routeCapacityOverview(array $filters = []): array
    {
        $routes = \App\Modules\SchoolOperations\Transportation\Models\TransportationRoute::query()
            ->where('active', true)
            ->when($filters['campus_id'] ?? null, fn ($q, $campus) => $q->where('campus_id', $campus))
            ->orderBy('code')->get();

        $rows = [];
        foreach ($routes as $route) {
            $rows[] = $this->routes->routeCapacity($route->id, $filters['date'] ?? null);
        }

        return [
            'date' => $filters['date'] ?? now()->toDateString(),
            'routes' => $rows,
            'total_capacity' => array_sum(array_column($rows, 'capacity')),
            'total_assigned' => array_sum(array_column($rows, 'assigned')),
            'total_available' => array_sum(array_column($rows, 'available_seats')),
            'waitlist_total' => array_sum(array_column($rows, 'waitlist_count')),
        ];
    }

    private function availableCapacity(array $payload): ?int
    {
        $vehicles = $this->availableVehiclesFor($payload);

        return $vehicles === [] ? null : (int) max(array_column(array_map(fn ($v) => $v['capacity'], $vehicles), 'usable_capacity'));
    }

    private function availableVehiclesFor(array $payload): array
    {
        return $this->fleet->availableVehicles([
            'date' => $payload['date'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
        ]);
    }

    private function estimateCost(int $vehicles, int $passengers, array $payload): array
    {
        $perKm = (float) ($payload['cost_per_km'] ?? 6500);
        $distance = (float) ($payload['distance_km'] ?? 0);
        $fuelPerKm = (float) ($payload['fuel_per_km'] ?? 0.35);
        $fuelPrice = (float) ($payload['fuel_price'] ?? 13500);

        $tripCost = $distance > 0 ? $perKm * $distance : null;
        $fuelCost = $distance > 0 ? $fuelPerKm * $distance * $fuelPrice : null;

        return [
            'fuel_cost_estimate' => $fuelCost !== null ? round($fuelCost * $vehicles, 2) : null,
            'operational_cost_estimate' => $tripCost !== null ? round($tripCost * $vehicles, 2) : null,
            'cost_per_passenger_estimate' => ($tripCost !== null && $passengers > 0) ? round(($tripCost * $vehicles) / $passengers, 2) : null,
            'assumptions' => ['cost_per_km' => $perKm, 'fuel_per_km' => $fuelPerKm, 'fuel_price' => $fuelPrice, 'distance_km' => $distance],
        ];
    }
}
