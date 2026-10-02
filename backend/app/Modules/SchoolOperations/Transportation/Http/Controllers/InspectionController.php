<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\{BoardingService, InspectionService};
use App\Modules\SchoolOperations\Transportation\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Checklist pra-trip & pasca-trip, sinkronisasi offline, dan gerbang keselamatan (§69, §70, §89). */
class InspectionController extends Controller
{
    public function __construct(
        private readonly InspectionService $inspections,
        private readonly BoardingService $boarding,
    ) {
    }

    public function template(Request $request): JsonResponse
    {
        $request->user()->can('transportation.inspection.view') || abort(403);

        return response()->json(['data' => [
            'pre_trip' => $this->inspections->checklistTemplate(),
            'post_trip_items' => config('transportation.post_trip_items'),
            'block_on_critical_failure' => (bool) config('transportation.dispatch.block_on_critical_pretrip_failure', true),
        ]]);
    }

    public function preTrip(Request $request): JsonResponse
    {
        $request->user()->can('transportation.inspection.perform') || abort(403);
        $validated = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'trip_id' => ['nullable', 'integer'],
            'driver_profile_id' => ['nullable', 'integer'],
            'items' => ['nullable', 'array'],
            'items.*.code' => ['required_with:items', 'string'],
            'items.*.result' => ['nullable', 'string'],
            'odometer' => ['nullable', 'numeric'],
            'fuel_level' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string'],
            'offline_sync' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->inspections->preTrip(
            $validated, $validated['idempotency_key'] ?? $request->header('Idempotency-Key')
        )], 201);
    }

    public function postTrip(Request $request): JsonResponse
    {
        $request->user()->can('transportation.inspection.perform') || abort(403);
        $validated = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'trip_id' => ['nullable', 'integer'],
            'vehicle_condition' => ['nullable', 'string'],
            'odometer' => ['nullable', 'numeric'],
            'fuel_level' => ['nullable', 'string'],
            'damage_found' => ['nullable', 'boolean'],
            'damage_description' => ['nullable', 'string'],
            'lost_items_found' => ['nullable', 'boolean'],
            'lost_items' => ['nullable', 'array'],
            'passenger_issues' => ['nullable', 'boolean'],
            'passenger_issue_notes' => ['nullable', 'string'],
            'incident_occurred' => ['nullable', 'boolean'],
            'cleaning_required' => ['nullable', 'boolean'],
            'maintenance_required' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->inspections->postTrip(
            $validated, $validated['idempotency_key'] ?? $request->header('Idempotency-Key')
        )], 201);
    }

    public function summary(Request $request, int $tripId): JsonResponse
    {
        $request->user()->can('transportation.inspection.view') || abort(403);
        $trip = Trip::query()->findOrFail($tripId);

        return response()->json(['data' => $this->inspections->summary($trip)]);
    }

    /** Sinkronisasi batch event offline dari aplikasi pengemudi (§89). */
    public function syncOffline(Request $request): JsonResponse
    {
        $request->user()->can('transportation.driver_app.use') || $request->user()->can('transportation.trip.operate') || abort(403);
        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:200'],
            'events.*.type' => ['required', 'string'],
            'events.*.payload' => ['required', 'array'],
            'events.*.idempotency_key' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->boarding->syncOfflineBatch($validated['events'])]);
    }

    /** Terima event pembacaan perangkat RFID/NFC/QR (§37). */
    public function scan(Request $request): JsonResponse
    {
        $request->user()->can('transportation.boarding.record') || abort(403);
        $validated = $request->validate([
            'trip_id' => ['nullable', 'integer'],
            'trip_number' => ['nullable', 'string'],
            'student_id' => ['nullable', 'integer'],
            'card_id' => ['nullable', 'string'],
            'token' => ['nullable', 'string'],
            'student_reference' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string'],
            'method' => ['nullable', 'string'],
            'stop_id' => ['nullable', 'integer'],
            'occurred_at' => ['nullable'],
            'scan_context' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->boarding->ingestScan($validated)], 201);
    }

    public function verifyToken(Request $request): JsonResponse
    {
        $request->user()->can('transportation.boarding.record') || abort(403);
        $validated = $request->validate(['token' => ['required', 'string']]);

        return response()->json(['data' => $this->boarding->verifyBoardingToken($validated['token'])]);
    }
}
