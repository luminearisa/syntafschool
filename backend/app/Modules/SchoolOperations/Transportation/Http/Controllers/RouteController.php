<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\{CapacityPlanningService, RouteService, TransportationAnalyticsService};
use App\Modules\SchoolOperations\Transportation\Domain\Contracts\RouteOptimizationProviderInterface;
use App\Modules\SchoolOperations\Transportation\Models\{RouteStop, RouteVersion, TransportationRoute};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Rute, versi, halte, kapasitas, batasan, dan fondasi optimasi (§16-§21, §27, §66, §67, §79). */
class RouteController extends Controller
{
    public function __construct(
        private readonly RouteService $routes,
        private readonly CapacityPlanningService $capacity,
        private readonly TransportationAnalyticsService $analytics,
        private readonly RouteOptimizationProviderInterface $optimizer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('transportation.route.view') || abort(403);

        return response()->json([
            'data' => $this->routes->activeRoutes($request->only(['campus_id', 'route_type', 'service_id'])),
            'optimization' => $this->optimizer->capabilities(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('transportation.route.manage') || abort(403);
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string'],
            'route_type' => ['nullable', 'string'],
            'campus_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'origin' => ['nullable', 'string'], 'destination' => ['nullable', 'string'],
            'distance_km' => ['nullable', 'numeric'],
            'estimated_duration_minutes' => ['nullable', 'integer'],
            'default_vehicle_capacity' => ['nullable', 'integer'],
            'max_stops' => ['nullable', 'integer'],
            'max_duration_minutes' => ['nullable', 'integer'],
            'pickup_window_start' => ['nullable'], 'pickup_window_end' => ['nullable'],
            'dropoff_window_start' => ['nullable'], 'dropoff_window_end' => ['nullable'],
            'school_arrival_deadline' => ['nullable'], 'school_departure_deadline' => ['nullable'],
            'status' => ['nullable', 'string'],
            'stops' => ['nullable', 'array'],
            'stops.*.name' => ['required_with:stops', 'string'],
            'stops.*.latitude' => ['nullable', 'numeric'], 'stops.*.longitude' => ['nullable', 'numeric'],
        ]);

        $route = $this->routes->createRoute($validated, $request->user()->id);

        return response()->json(['data' => [
            'route' => $route->only(['id', 'code', 'name', 'route_type', 'status', 'version_number']),
            'stops' => $this->routes->stops($route->id),
        ]], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.route.view') || abort(403);
        $route = TransportationRoute::query()->findOrFail($id);

        return response()->json(['data' => [
            'route' => $route->toArray(),
            'version' => RouteVersion::query()->find($route->current_version_id)?->toArray(),
            'stops' => $this->routes->stops($route->id),
            'capacity' => $this->routes->routeCapacity($route->id, $request->input('date')),
            'constraints' => $this->routes->validateConstraints($route, $request->only(['passengers', 'vehicle_capacity'])),
            'optimization_data' => $this->routes->optimizationData($route->id),
            'versions' => RouteVersion::query()->where('route_id', $route->id)->orderByDesc('version_number')
                ->get(['id', 'version_number', 'status', 'effective_from', 'effective_until', 'change_summary'])->toArray(),
        ]]);
    }

    /** Perubahan rute bersifat aditif: versi baru, bukan menghapus versi lama (§18). */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.route.manage') || abort(403);
        $route = TransportationRoute::query()->findOrFail($id);
        $validated = $request->validate([
            'name' => ['nullable', 'string'],
            'change_summary' => ['nullable', 'string'],
            'stops' => ['nullable', 'array'],
            'stops.*.name' => ['required_with:stops', 'string'],
        ]);

        $version = $this->routes->updateRoute($route, $validated, $validated['change_summary'] ?? null, $request->user()->id);

        return response()->json(['data' => [
            'version' => $version->only(['id', 'version_number', 'status', 'change_summary', 'effective_from']),
            'stops' => $this->routes->stops($route->id),
        ]]);
    }

    public function approveVersion(Request $request, int $versionId): JsonResponse
    {
        $request->user()->can('transportation.route.approve') || abort(403);
        $version = RouteVersion::query()->findOrFail($versionId);

        return response()->json(['data' => $this->routes->approveVersion($version, $request->user()->id)]);
    }

    public function stops(Request $request, int $routeId): JsonResponse
    {
        $request->user()->can('transportation.route.view') || abort(403);

        return response()->json(['data' => $this->routes->stops($routeId, $request->input('version_id'))]);
    }

    public function addStop(Request $request, int $routeId): JsonResponse
    {
        $request->user()->can('transportation.route.manage') || abort(403);
        $route = TransportationRoute::query()->findOrFail($routeId);
        $validated = $request->validate([
            'sequence' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string'],
            'stop_type' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric'], 'longitude' => ['nullable', 'numeric'],
            'geofence_radius_meters' => ['nullable', 'integer', 'min:10'],
            'planned_arrival' => ['nullable'], 'planned_departure' => ['nullable'],
            'pickup_allowed' => ['nullable', 'boolean'], 'dropoff_allowed' => ['nullable', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => $this->routes->addStop($route, $validated)], 201);
    }

    public function updateStop(Request $request, int $stopId): JsonResponse
    {
        $request->user()->can('transportation.route.manage') || abort(403);
        $stop = RouteStop::query()->findOrFail($stopId);

        return response()->json(['data' => $this->routes->updateStop($stop, $request->all())]);
    }

    public function removeStop(Request $request, int $stopId): JsonResponse
    {
        $request->user()->can('transportation.route.manage') || abort(403);
        $stop = RouteStop::query()->findOrFail($stopId);

        return response()->json(['data' => $this->routes->removeStop($stop, $request->user()->id, $request->input('reason'))]);
    }

    public function capacity(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.route.view') || abort(403);

        return response()->json(['data' => $this->routes->routeCapacity($id, $request->input('date'))]);
    }

    public function capacityOverview(Request $request): JsonResponse
    {
        $request->user()->can('transportation.route.view') || abort(403);

        return response()->json(['data' => $this->capacity->routeCapacityOverview($request->only(['date', 'campus_id']))]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $request->user()->can('transportation.analytics.view') || abort(403);

        return response()->json(['data' => $this->analytics->routeAnalytics($request->input('from'), $request->input('to'), $request->only(['campus_id']))]);
    }

    public function optimizationData(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.route.view') || abort(403);

        return response()->json(['data' => $this->routes->optimizationData($id, $request->input('date'))]);
    }
}
