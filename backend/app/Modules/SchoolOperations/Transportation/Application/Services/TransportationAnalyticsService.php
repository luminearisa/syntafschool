<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\{
    DriverAssignment, DriverProfile, TransportationCost, TransportationIncident, TransportationRoute, Trip,
    TripManifest, TripPassenger, VehicleDocument, VehicleFuelLog, VehicleProfile
};
use Illuminate\Support\Facades\DB;

/**
 * Analitik & laporan transportasi (§75, §78-§82).
 *
 * Semua angka berasal dari data operasional nyata. Metrik pengemudi disajikan
 * sebagai laporan operasional — bukan dasar keputusan kepegawaian otomatis.
 */
class TransportationAnalyticsService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly FleetService $fleet,
        private readonly RouteService $routes,
        private readonly FuelCostService $costs,
    ) {
    }

    /** Dashboard admin (§75). */
    public function dashboard(array $filters = []): array
    {
        $date = $filters['date'] ?? now()->toDateString();
        $campusId = $filters['campus_id'] ?? null;

        $trips = Trip::query()->whereDate('trip_date', $date)
            ->when($campusId, fn ($q, $campus) => $q->where('campus_id', $campus));

        $tripIds = (clone $trips)->pluck('id')->all();
        $passengers = TripPassenger::query()->whereIn('trip_id', $tripIds);
        $costSummary = $this->costs->summary($date, $date, $campusId ? [] : []);

        return [
            'date' => $date,
            'trips' => [
                'total' => (clone $trips)->count(),
                'completed' => (clone $trips)->where('status', 'COMPLETED')->count(),
                'active' => (clone $trips)->whereIn('status', ['DISPATCHED', 'BOARDING', 'IN_TRANSIT', 'ARRIVED'])->count(),
                'delayed' => (clone $trips)->where('delay_minutes', '>', 0)->count(),
                'cancelled' => (clone $trips)->where('status', 'CANCELLED')->count(),
                'unassigned' => (clone $trips)->where(fn ($q) => $q->whereNull('vehicle_id')->orWhereNull('driver_profile_id'))->count(),
            ],
            'vehicles' => [
                'total' => VehicleProfile::query()->count(),
                'available' => VehicleProfile::query()->where('status', 'AVAILABLE')->count(),
                'on_trip' => VehicleProfile::query()->where('status', 'ON_TRIP')->count(),
                'maintenance' => VehicleProfile::query()->where('status', 'MAINTENANCE')->count(),
                'inspection' => VehicleProfile::query()->where('status', 'INSPECTION')->count(),
            ],
            'drivers' => [
                'total' => DriverProfile::query()->count(),
                'on_duty' => DriverProfile::query()->whereIn('driver_status', ['ON_DUTY', 'ON_TRIP'])->count(),
                'available' => DriverProfile::query()->where('driver_status', 'AVAILABLE')->count(),
                'unavailable' => DriverProfile::query()->whereIn('driver_status', ['LEAVE', 'OFF_DUTY', 'SUSPENDED', 'INACTIVE'])->count(),
            ],
            'passengers' => [
                'expected' => (clone $passengers)->count(),
                'transported' => (clone $passengers)->where('dropoff_status', 'DROPPED_OFF')->count(),
                'boarded' => (clone $passengers)->where('boarding_status', 'BOARDED')->count(),
                'not_boarded' => (clone $passengers)->whereIn('boarding_status', ['NO_SHOW', 'ABSENT', 'EXPECTED'])->count(),
                'no_show' => (clone $passengers)->where('boarding_status', 'NO_SHOW')->count(),
            ],
            'incidents' => [
                'open' => TransportationIncident::query()->whereNotIn('status', ['CLOSED', 'RESOLVED'])->count(),
                'today' => TransportationIncident::query()->whereDate('occurred_at', $date)->count(),
                'critical' => TransportationIncident::query()->where('severity', 'CRITICAL')->whereNotIn('status', ['CLOSED'])->count(),
            ],
            'documents' => [
                'expiring_soon' => count($this->fleet->expiringDocuments(30)),
                'expired' => count(array_filter($this->fleet->expiringDocuments(0), fn (array $doc): bool => $doc['expired'])),
            ],
            'cost' => [
                'today_total' => $costSummary['total_cost'],
                'cost_per_passenger' => $costSummary['cost_per_passenger'],
                'cost_per_trip' => $costSummary['cost_per_trip'],
                'by_category' => $costSummary['by_category'],
            ],
            'route_capacity' => app(CapacityPlanningService::class)->routeCapacityOverview(['date' => $date, 'campus_id' => $campusId]),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Metrik operasional agregat (§78). */
    public function tripMetrics(?string $from = null, ?string $to = null, array $filters = []): array
    {
        $from ??= now()->subDays(29)->toDateString();
        $to ??= now()->toDateString();

        $trips = Trip::query()->whereDate('trip_date', '>=', $from)->whereDate('trip_date', '<=', $to)
            ->when($filters['route_id'] ?? null, fn ($q, $routeId) => $q->where('route_id', $routeId))
            ->when($filters['vehicle_id'] ?? null, fn ($q, $vehicleId) => $q->where('vehicle_id', $vehicleId))
            ->when($filters['driver_profile_id'] ?? null, fn ($q, $driverId) => $q->where('driver_profile_id', $driverId))
            ->when($filters['campus_id'] ?? null, fn ($q, $campus) => $q->where('campus_id', $campus))
            ->get();

        $tripIds = $trips->pluck('id')->all();
        $passengers = TripPassenger::query()->whereIn('trip_id', $tripIds);
        $boarded = (clone $passengers)->where('boarding_status', 'BOARDED')->count();
        $noShows = (clone $passengers)->where('boarding_status', 'NO_SHOW')->count();
        $manifestCapacity = (int) TripManifest::query()->whereIn('trip_id', $tripIds)->sum('capacity');
        $completed = $trips->where('status', 'COMPLETED')->count();
        $delayedTrips = $trips->where('delay_minutes', '>', 0)->count();
        $distance = round((float) $trips->sum('distance_km'), 2);
        $fuel = (float) VehicleFuelLog::query()->whereIn('trip_id', $tripIds)->sum('fuel_quantity');
        $costSummary = $this->costs->summary($from, $to, $filters);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'trips' => [
                'total' => $trips->count(),
                'completed' => $completed,
                'cancelled' => $trips->where('status', 'CANCELLED')->count(),
                'delayed' => $delayedTrips,
                'on_time' => max(0, $completed - $delayedTrips),
            ],
            'passengers' => [
                'manifested' => (clone $passengers)->count(),
                'boarded' => $boarded,
                'no_show' => $noShows,
                'no_show_rate' => ((clone $passengers)->count()) > 0
                    ? round($noShows / (clone $passengers)->count() * 100, 2) : 0.0,
            ],
            'occupancy_percent' => $manifestCapacity > 0 ? round($boarded / $manifestCapacity * 100, 2) : 0.0,
            'on_time_rate' => $completed > 0 ? round(max(0, $completed - $delayedTrips) / $completed * 100, 2) : 0.0,
            'average_delay_minutes' => $delayedTrips > 0 ? round((float) $trips->sum('delay_minutes') / $delayedTrips, 2) : 0.0,
            'cancellation_rate' => $trips->count() > 0 ? round($trips->where('status', 'CANCELLED')->count() / $trips->count() * 100, 2) : 0.0,
            'incident_rate' => $trips->count() > 0
                ? round(TransportationIncident::query()->whereIn('trip_id', $tripIds)->count() / $trips->count() * 100, 2) : 0.0,
            'distance_km' => $distance,
            'fuel' => [
                'quantity' => round($fuel, 2),
                'efficiency_km_per_liter' => $fuel > 0 ? round($distance / $fuel, 2) : null,
            ],
            'cost' => [
                'total' => $costSummary['total_cost'],
                'cost_per_trip' => $costSummary['cost_per_trip'],
                'cost_per_passenger' => $costSummary['cost_per_passenger'],
                'cost_per_km' => $costSummary['cost_per_km'],
            ],
        ];
    }

    /** Analitik per rute (§79). */
    public function routeAnalytics(?string $from = null, ?string $to = null, array $filters = []): array
    {
        $from ??= now()->subDays(29)->toDateString();
        $to ??= now()->toDateString();

        $routes = TransportationRoute::query()
            ->when($filters['campus_id'] ?? null, fn ($q, $campus) => $q->where('campus_id', $campus))
            ->orderBy('code')->get();

        $result = [];
        foreach ($routes as $route) {
            $trips = Trip::query()->where('route_id', $route->id)->whereDate('trip_date', '>=', $from)->whereDate('trip_date', '<=', $to);
            $tripIds = (clone $trips)->pluck('id')->all();
            $boarded = TripPassenger::query()->whereIn('trip_id', $tripIds)->where('boarding_status', 'BOARDED')->count();
            $capacity = (int) TripManifest::query()->whereIn('trip_id', $tripIds)->sum('capacity');
            $delayed = (clone $trips)->where('delay_minutes', '>', 0)->count();
            $cost = (float) TransportationCost::query()->where(function ($q) use ($route, $tripIds) {
                $q->where('route_id', $route->id)->orWhereIn('trip_id', $tripIds);
            })->whereDate('cost_date', '>=', $from)->whereDate('cost_date', '<=', $to)->sum('amount');

            $result[] = [
                'route_id' => $route->id,
                'route_code' => $route->code,
                'route_name' => $route->name,
                'capacity' => $this->routes->routeCapacity($route->id)['capacity'],
                'trips' => (clone $trips)->count(),
                'passengers_boarded' => $boarded,
                'average_passengers_per_trip' => (clone $trips)->count() > 0 ? round($boarded / (clone $trips)->count(), 2) : 0.0,
                'occupancy_percent' => $capacity > 0 ? round($boarded / $capacity * 100, 2) : 0.0,
                'distance_km' => round((float) (clone $trips)->sum('distance_km'), 2),
                'estimated_duration_minutes' => $route->estimated_duration_minutes,
                'average_delay_minutes' => $delayed > 0 ? round((float) (clone $trips)->sum('delay_minutes') / $delayed, 2) : 0.0,
                'fuel_liters' => round((float) VehicleFuelLog::query()->whereIn('trip_id', $tripIds)->sum('fuel_quantity'), 2),
                'cost' => round($cost, 2),
                'incidents' => TransportationIncident::query()->where(function ($q) use ($route, $tripIds) {
                    $q->where('route_id', $route->id)->orWhereIn('trip_id', $tripIds);
                })->count(),
            ];
        }

        return ['period' => ['from' => $from, 'to' => $to], 'routes' => $result];
    }

    /** Analitik per kendaraan (§80). */
    public function vehicleAnalytics(?string $from = null, ?string $to = null): array
    {
        $from ??= now()->subDays(29)->toDateString();
        $to ??= now()->toDateString();

        return VehicleProfile::query()->orderBy('vehicle_code')->get()->map(function (VehicleProfile $vehicle) use ($from, $to): array {
            $trips = Trip::query()->where('vehicle_id', $vehicle->id)->whereDate('trip_date', '>=', $from)->whereDate('trip_date', '<=', $to);
            $tripIds = (clone $trips)->pluck('id')->all();
            $fuel = $this->fleet->fuelMetrics($vehicle->id, $from, $to);
            $costSummary = $this->costs->vehicleCost($vehicle->id, $from, $to);
            $maintenanceCost = (float) TransportationCost::query()->where('vehicle_id', $vehicle->id)
                ->where('cost_category', 'MAINTENANCE')->whereDate('cost_date', '>=', $from)->whereDate('cost_date', '<=', $to)->sum('amount');

            return [
                'vehicle_id' => $vehicle->id,
                'vehicle_code' => $vehicle->vehicle_code,
                'status' => $vehicle->status,
                'trips' => (clone $trips)->count(),
                'distance_km' => round((float) (clone $trips)->sum('distance_km'), 2),
                'fuel' => $fuel,
                'maintenance_cost' => round($maintenanceCost, 2),
                'downtime_trips' => (clone $trips)->where('status', 'CANCELLED')->count(),
                'incidents' => TransportationIncident::query()->where('vehicle_id', $vehicle->id)->count(),
                'cost' => $costSummary['total_cost'],
                'utilization_percent' => $trips->count() > 0
                    ? round(min(100, (clone $trips)->count() / max(1, now()->parse($from)->diffInDays($to) + 1) * 100), 2) : 0.0,
            ];
        })->all();
    }

    /**
     * Laporan operasional pengemudi (§81).
     * Disajikan sebagai data operasional; keputusan terkait employment tidak
     * boleh diambil hanya dari satu metrik.
     */
    public function driverAnalytics(?string $from = null, ?string $to = null): array
    {
        $from ??= now()->subDays(29)->toDateString();
        $to ??= now()->toDateString();

        $rows = DriverProfile::query()->orderBy('driver_code')->get()->map(function (DriverProfile $driver) use ($from, $to): array {
            $trips = Trip::query()->where('driver_profile_id', $driver->id)->whereDate('trip_date', '>=', $from)->whereDate('trip_date', '<=', $to);
            $tripIds = (clone $trips)->pluck('id')->all();
            $delayed = (clone $trips)->where('delay_minutes', '>', 0)->count();
            $checklists = DB::table('pre_trip_inspections')->where('driver_profile_id', $driver->id)
                ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->count();
            $trainingValid = $driver->qualifications()->where('status', 'VALID')->count();
            $trainingRequired = count(array_filter((array) config('transportation.qualification_types', []),
                fn (array $item): bool => (bool) ($item['required_for_dispatch'] ?? false)));

            return [
                'driver_profile_id' => $driver->id,
                'driver_code' => $driver->driver_code,
                'trips' => (clone $trips)->count(),
                'distance_km' => round((float) (clone $trips)->sum('distance_km'), 2),
                'operating_hours' => round((float) (clone $trips)->sum('distance_km') / 30, 2),
                'delays' => $delayed,
                'incidents' => TransportationIncident::query()->where('driver_profile_id', $driver->id)->count(),
                'checklist_completion' => (clone $trips)->count() > 0
                    ? round($checklists / max(1, (clone $trips)->count()) * 100, 2) : 0.0,
                'training_status' => ['valid' => $trainingValid, 'required' => $trainingRequired,
                    'complete' => $trainingValid >= $trainingRequired],
            ];
        })->all();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'drivers' => $rows,
            'disclaimer' => 'Angka ini adalah laporan operasional. Penilaian kinerja pegawai tetap mengikuti proses HR yang berlaku, '
                .'bukan kesimpulan otomatis dari satu metrik transportasi.',
        ];
    }

    /** Laporan harian (§82). */
    public function dailyReport(string $date): array
    {
        $trips = Trip::query()->whereDate('trip_date', $date)->with(['route', 'vehicle', 'driver'])->get();
        $tripIds = $trips->pluck('id')->all();
        $passengers = TripPassenger::query()->whereIn('trip_id', $tripIds)->get();

        return [
            'date' => $date,
            'trip_report' => $trips->map(fn (Trip $trip): array => [
                'trip_number' => $trip->trip_number,
                'route' => $trip->route?->name,
                'vehicle' => $trip->vehicle?->vehicle_code,
                'driver' => $trip->driver?->full_name,
                'status' => $trip->status,
                'planned_departure' => $trip->planned_departure,
                'actual_start_at' => $trip->actual_start_at,
                'delay_minutes' => (int) $trip->delay_minutes,
                'boarded' => (int) $trip->boarded_count,
            ])->all(),
            'manifest' => $passengers->map(fn (TripPassenger $passenger): array => [
                'trip_id' => $passenger->trip_id,
                'student_id' => $passenger->student_id,
                'boarding_status' => $passenger->boarding_status,
                'dropoff_status' => $passenger->dropoff_status,
                'boarded_at' => $passenger->boarded_at,
                'dropped_off_at' => $passenger->dropped_off_at,
            ])->all(),
            'boarding_report' => [
                'boarded' => $passengers->where('boarding_status', 'BOARDED')->count(),
                'no_show' => $passengers->where('boarding_status', 'NO_SHOW')->count(),
                'absent' => $passengers->where('boarding_status', 'ABSENT')->count(),
                'expected' => $passengers->count(),
            ],
            'dropoff_report' => [
                'dropped_off' => $passengers->where('dropoff_status', 'DROPPED_OFF')->count(),
                'authorized_pickup' => $passengers->where('dropoff_status', 'AUTHORIZED_PICKUP')->count(),
                'not_dropped_off' => $passengers->where('dropoff_status', 'NOT_DROPPED_OFF')->count(),
            ],
            'incident_report' => $this->incidentsForReport($tripIds),
            'vehicle_report' => $trips->groupBy('vehicle_id')->map->count()->all(),
            'driver_report' => $trips->groupBy('driver_profile_id')->map->count()->all(),
        ];
    }

    /** Laporan bulanan (§82). */
    public function monthlyReport(?string $month = null): array
    {
        $start = \Carbon\CarbonImmutable::parse($month ?? now()->format('Y-m'))
            ->startOfMonth();
        $end = $start->endOfMonth();

        return [
            'month' => $start->format('Y-m'),
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'metrics' => $this->tripMetrics($start->toDateString(), $end->toDateString()),
            'routes' => $this->routeAnalytics($start->toDateString(), $end->toDateString())['routes'],
            'vehicles' => $this->vehicleAnalytics($start->toDateString(), $end->toDateString()),
            'costs' => $this->costs->summary($start->toDateString(), $end->toDateString()),
            'attendance' => [
                'boarded' => TripPassenger::query()->whereIn('trip_id', Trip::query()
                    ->whereDate('trip_date', '>=', $start->toDateString())->whereDate('trip_date', '<=', $end->toDateString())->pluck('id'))
                    ->where('boarding_status', 'BOARDED')->count(),
                'no_show' => TripPassenger::query()->whereIn('trip_id', Trip::query()
                    ->whereDate('trip_date', '>=', $start->toDateString())->whereDate('trip_date', '<=', $end->toDateString())->pluck('id'))
                    ->where('boarding_status', 'NO_SHOW')->count(),
            ],
            'incidents' => TransportationIncident::query()
                ->whereBetween('occurred_at', [$start->toDateTimeString(), $end->toDateTimeString()])
                ->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity')->all(),
        ];
    }

    /** Laporan manajemen: biaya & utilisasi (§82). */
    public function managementReport(?string $from = null, ?string $to = null): array
    {
        $from ??= now()->startOfYear()->toDateString();
        $to ??= now()->toDateString();

        $cost = $this->costs->summary($from, $to);
        $metrics = $this->tripMetrics($from, $to);
        $studentsServed = TripPassenger::query()->whereIn('trip_id', Trip::query()
            ->whereDate('trip_date', '>=', $from)->whereDate('trip_date', '<=', $to)->pluck('id'))->distinct('student_id')->count('student_id');

        return [
            'period' => ['from' => $from, 'to' => $to],
            'transportation_cost' => $cost['total_cost'],
            'cost_per_student' => $studentsServed > 0 ? round($cost['total_cost'] / $studentsServed, 2) : null,
            'students_served' => $studentsServed,
            'cost_per_route' => array_map(fn (array $route): array => [
                'route' => $route['route_code'],
                'cost' => $route['cost'],
                'passengers' => $route['passengers_boarded'],
                'cost_per_passenger' => $route['passengers_boarded'] > 0 ? round($route['cost'] / $route['passengers_boarded'], 2) : null,
            ], $this->routeAnalytics($from, $to)['routes']),
            'fleet_utilization' => $this->vehicleAnalytics($from, $to),
            'capacity_analysis' => app(CapacityPlanningService::class)->routeCapacityOverview(),
            'cost_breakdown' => $cost['by_category'],
            'trip_metrics' => $metrics,
        ];
    }

    private function incidentsForReport(array $tripIds): array
    {
        return TransportationIncident::query()->whereIn('trip_id', $tripIds)->get()
            ->map(fn (TransportationIncident $incident): array => [
                'incident_number' => $incident->incident_number,
                'type' => $incident->incident_type,
                'severity' => $incident->severity,
                'status' => $incident->status,
                'occurred_at' => $incident->occurred_at,
            ])->all();
    }

    /** Peringatan operasional terpadu (dokumen, kapasitas, trip belum lengkap). */
    public function alerts(array $filters = []): array
    {
        $tomorrow = now()->addDay()->toDateString();
        $unassigned = Trip::query()->whereDate('trip_date', $tomorrow)
            ->where(fn ($q) => $q->whereNull('vehicle_id')->orWhereNull('driver_profile_id'))
            ->count();
        $expiring = $this->fleet->expiringDocuments(30);
        $capacity = app(CapacityPlanningService::class)->routeCapacityOverview();

        return [
            'documents_expiring' => $expiring,
            'documents_expiring_count' => count($expiring),
            'trips_without_assignment_tomorrow' => $unassigned,
            'full_routes' => array_values(array_filter($capacity['routes'], fn (array $route): bool => $route['is_full'])),
            'open_incidents' => TransportationIncident::query()->whereNotIn('status', ['CLOSED', 'RESOLVED'])->count(),
            'pending_costs' => TransportationCost::query()->where('finance_status', 'PENDING')->count(),
            'waiting_list_total' => DB::table('transportation_waitlists')->where('status', 'WAITING')->count(),
        ];
    }
}
