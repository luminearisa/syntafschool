<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\{BoardingService, ManifestService, TransportationScopeService, TripService};
use App\Modules\SchoolOperations\Transportation\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Antarmuka pengemudi (§40/§41/§77): trip hari ini, trip aktif, halte
 * langkah-demi-langkah, manifes, aksi operasi, dan dukungan offline.
 */
class DriverAppController extends Controller
{
    public function __construct(
        private readonly TripService $trips,
        private readonly ManifestService $manifest,
        private readonly BoardingService $boarding,
        private readonly TransportationScopeService $scope,
    ) {
    }

    private function authorizeDriver(Request $request): void
    {
        $request->user()->can('transportation.driver_app.use') || $request->user()->can('transportation.trip.operate') || abort(403);
    }

    public function today(Request $request): JsonResponse
    {
        $this->authorizeDriver($request);
        $driver = $this->scope->driverProfile($request->user());
        $date = (string) $request->input('date', now()->toDateString());

        $trips = Trip::query()->whereDate('trip_date', $date)
            ->when($driver, fn ($q) => $q->where('driver_profile_id', $driver->id))
            ->with(['route:id,code,name', 'vehicle:id,vehicle_code,registration_number,student_capacity'])
            ->orderBy('planned_departure')
            ->get()
            ->map(fn (Trip $trip): array => [
                'id' => $trip->id,
                'trip_number' => $trip->trip_number,
                'planned_departure' => $trip->planned_departure,
                'direction' => $trip->direction,
                'status' => $trip->status,
                'route' => $trip->route?->name,
                'vehicle' => $trip->vehicle?->vehicle_code,
                'passengers' => (int) $trip->planned_passengers,
                'delay_minutes' => (int) $trip->delay_minutes,
            ])->all();

        return response()->json(['data' => [
            'date' => $date,
            'driver' => $driver?->only(['id', 'driver_code', 'full_name', 'driver_status']),
            'trips' => $trips,
            'current_trip' => $trips[0] ?? null,
            'offline_hint' => [
                'manifest_cache_hours' => (int) config('transportation.offline.manifest_cache_hours', 24),
                'max_queue_size' => (int) config('transportation.offline.max_queue_size', 500),
                'conflict_resolution' => config('transportation.offline.conflict_resolution'),
            ],
        ]]);
    }

    public function current(Request $request): JsonResponse
    {
        $this->authorizeDriver($request);
        $driver = $this->scope->driverProfile($request->user());

        $trip = Trip::query()->whereDate('trip_date', now()->toDateString())
            ->when($driver, fn ($q) => $q->where('driver_profile_id', $driver->id))
            ->whereIn('status', ['DISPATCHED', 'BOARDING', 'IN_TRANSIT', 'ARRIVED', 'DELAYED', 'INCIDENT'])
            ->orderBy('planned_departure')->first();

        if (! $trip) {
            return response()->json(['data' => null, 'message' => 'Tidak ada perjalanan aktif saat ini.']);
        }

        return response()->json(['data' => $this->trips->tripSnapshot($trip) + [
            'manifest' => $this->manifest->manifestSnapshot($trip),
            'stops' => $this->boarding->tripStopsWithCounts($trip->id),
        ]]);
    }

    /** Halte langkah-demi-langkah dengan jumlah penumpang & status (§41). */
    public function stops(Request $request, int $tripId): JsonResponse
    {
        $this->authorizeDriver($request);
        $trip = Trip::query()->findOrFail($tripId);
        abort_unless($this->scope->canOperateTrip($request->user(), $trip), 403);

        return response()->json(['data' => [
            'trip_id' => $trip->id,
            'trip_number' => $trip->trip_number,
            'status' => $trip->status,
            'stops' => $this->boarding->tripStopsWithCounts($trip->id),
            'passengers' => $this->manifest->manifestSnapshot($trip)['passengers'],
        ]]);
    }

    /** Manifes cache-untuk-offline (§89). */
    public function offlineManifest(Request $request, int $tripId): JsonResponse
    {
        $this->authorizeDriver($request);
        $trip = Trip::query()->findOrFail($tripId);
        abort_unless($this->scope->canOperateTrip($request->user(), $trip), 403);

        return response()->json(['data' => [
            'trip' => $this->trips->tripSnapshot($trip),
            'manifest' => $this->manifest->manifestSnapshot($trip),
            'checklist_template' => config('transportation.pre_trip_items'),
            'cached_at' => now()->toIso8601String(),
            'valid_for_hours' => (int) config('transportation.offline.manifest_cache_hours', 24),
            'operations' => ['START', 'ARRIVE_STOP', 'BOARD', 'NO_SHOW', 'DEPART_STOP', 'DROPOFF', 'CHECKLIST', 'INCIDENT', 'COMPLETE'],
        ]]);
    }
}
