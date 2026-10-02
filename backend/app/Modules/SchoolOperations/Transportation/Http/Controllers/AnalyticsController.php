<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\{
    CapacityPlanningService, DailyClosingService, FuelCostService, TransportationAnalyticsService
};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Dashboard, analitik, laporan, biaya, perencanaan kapasitas, dan penutupan harian (§58-§61, §64, §65, §75, §78-§82). */
class AnalyticsController extends Controller
{
    public function __construct(
        private readonly TransportationAnalyticsService $analytics,
        private readonly FuelCostService $costs,
        private readonly CapacityPlanningService $capacity,
        private readonly DailyClosingService $closings,
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $request->user()->can('transportation.dashboard.view') || $request->user()->can('transportation.view') || abort(403);

        return response()->json(['data' => $this->analytics->dashboard($request->only(['date', 'campus_id']))]);
    }

    public function metrics(Request $request): JsonResponse
    {
        $request->user()->can('transportation.analytics.view') || abort(403);

        return response()->json(['data' => $this->analytics->tripMetrics(
            $request->input('from'), $request->input('to'),
            $request->only(['route_id', 'vehicle_id', 'driver_profile_id', 'campus_id'])
        )]);
    }

    public function routeAnalytics(Request $request): JsonResponse
    {
        $request->user()->can('transportation.analytics.view') || abort(403);

        return response()->json(['data' => $this->analytics->routeAnalytics(
            $request->input('from'), $request->input('to'), $request->only(['campus_id'])
        )]);
    }

    public function vehicleAnalytics(Request $request): JsonResponse
    {
        $request->user()->can('transportation.analytics.view') || abort(403);

        return response()->json(['data' => $this->analytics->vehicleAnalytics($request->input('from'), $request->input('to'))]);
    }

    public function driverAnalytics(Request $request): JsonResponse
    {
        $request->user()->can('transportation.analytics.view') || abort(403);

        return response()->json(['data' => $this->analytics->driverAnalytics($request->input('from'), $request->input('to'))]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $request->user()->can('transportation.analytics.view') || abort(403);

        return response()->json(['data' => $this->analytics->alerts()]);
    }

    public function dailyReport(Request $request): JsonResponse
    {
        $request->user()->can('transportation.report.view') || abort(403);

        return response()->json(['data' => $this->analytics->dailyReport((string) $request->input('date', now()->toDateString()))]);
    }

    public function monthlyReport(Request $request): JsonResponse
    {
        $request->user()->can('transportation.report.view') || abort(403);

        return response()->json(['data' => $this->analytics->monthlyReport($request->input('month'))]);
    }

    public function managementReport(Request $request): JsonResponse
    {
        $request->user()->can('transportation.report.view') || abort(403);

        return response()->json(['data' => $this->analytics->managementReport($request->input('from'), $request->input('to'))]);
    }

    public function costs(Request $request): JsonResponse
    {
        $request->user()->can('transportation.cost.view') || abort(403);

        return response()->json(['data' => $this->costs->summary(
            $request->input('from'), $request->input('to'),
            $request->only(['vehicle_id', 'route_id', 'trip_id'])
        )]);
    }

    public function storeCost(Request $request): JsonResponse
    {
        $request->user()->can('transportation.cost.manage') || abort(403);
        $validated = $request->validate([
            'cost_category' => ['required', 'string'],
            'trip_id' => ['nullable', 'integer'],
            'vehicle_id' => ['nullable', 'integer'],
            'driver_profile_id' => ['nullable', 'integer'],
            'route_id' => ['nullable', 'integer'],
            'cost_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric'],
            'unit' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'receipt_number' => ['nullable', 'string'],
            'vendor_id' => ['nullable', 'integer'],
            'billable' => ['nullable', 'boolean'],
            'post_to_finance' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->costs->recordCost(
            $validated, $validated['idempotency_key'] ?? $request->header('Idempotency-Key')
        )], 201);
    }

    public function postPendingCosts(Request $request): JsonResponse
    {
        $request->user()->can('transportation.cost.manage') || abort(403);

        return response()->json(['data' => $this->costs->postPendingCosts($request->input('from'), $request->input('to'))]);
    }

    public function planCapacity(Request $request): JsonResponse
    {
        $request->user()->can('transportation.view') || abort(403);
        $validated = $request->validate([
            'passengers' => ['required', 'integer', 'min:1'],
            'vehicle_capacity' => ['nullable', 'integer', 'min:1'],
            'campus_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'distance_km' => ['nullable', 'numeric'],
            'event_name' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->capacity->planRequirements($validated)]);
    }

    public function planEvent(Request $request): JsonResponse
    {
        $request->user()->can('transportation.view') || abort(403);
        $validated = $request->validate([
            'passengers' => ['required', 'integer', 'min:1'],
            'route_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'event_name' => ['nullable', 'string'],
            'create_trips' => ['nullable', 'boolean'],
        ]);

        if (($validated['create_trips'] ?? false) && ! empty($validated['route_id'])) {
            return response()->json(['data' => $this->capacity->createEventTrips($validated)], 201);
        }

        return response()->json(['data' => $this->capacity->planEventTransport($validated)]);
    }

    public function closings(Request $request): JsonResponse
    {
        $request->user()->can('transportation.report.view') || abort(403);

        return response()->json(['data' => $this->closings->closings($request->only(['from', 'to', 'status', 'limit']))]);
    }

    public function closeDay(Request $request): JsonResponse
    {
        $request->user()->can('transportation.trip.close') || abort(403);
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'campus_id' => ['nullable', 'integer'],
            'force' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->closings->close(
            $validated['date'], $validated['campus_id'] ?? null, $request->user()->id, (bool) ($validated['force'] ?? false)
        )], 201);
    }

    public function preflight(Request $request): JsonResponse
    {
        $request->user()->can('transportation.trip.close') || abort(403);

        return response()->json(['data' => $this->closings->preflight(
            (string) $request->input('date', now()->toDateString()), $request->input('campus_id')
        )]);
    }

    public function reopenDay(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.adjustment.approve') || abort(403);
        $validated = $request->validate(['reason' => ['required', 'string']]);

        return response()->json(['data' => $this->closings->reopen($id, $validated['reason'], $request->user()->id)]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $request->user()->can('transportation.adjustment.approve') || abort(403);
        $validated = $request->validate([
            'reference_type' => ['nullable', 'string'],
            'reference_id' => ['required', 'integer'],
            'adjustment_type' => ['required', 'string'],
            'reason' => ['required', 'string'],
            'boarding_status' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric'],
            'trip_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $this->closings->adjust($validated)], 201);
    }
}
