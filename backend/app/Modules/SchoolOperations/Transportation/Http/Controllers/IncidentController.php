<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\IncidentService;
use App\Modules\SchoolOperations\Transportation\Models\{TransportationIncident, Trip};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Insiden, eskalasi, tindakan darurat, dan alur mogok kendaraan (§45-§49). */
class IncidentController extends Controller
{
    public function __construct(private readonly IncidentService $incidents)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('transportation.incident.view') || abort(403);

        return response()->json(['data' => $this->incidents->incidents($request->only([
            'status', 'severity', 'incident_type', 'trip_id', 'from', 'to', 'limit',
        ]))]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('transportation.incident.report') || $request->user()->can('transportation.incident.manage') || abort(403);
        $validated = $request->validate([
            'incident_type' => ['required', 'string'],
            'severity' => ['nullable', 'string'],
            'trip_id' => ['nullable', 'integer'],
            'vehicle_id' => ['nullable', 'integer'],
            'driver_profile_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'occurred_at' => ['nullable'],
            'location_description' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric'], 'longitude' => ['nullable', 'numeric'],
            'description' => ['required', 'string'],
            'injured_count' => ['nullable', 'integer', 'min:0'],
            'passengers_involved' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string'],
            'offline_sync' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->incidents->report(
            $validated, $validated['idempotency_key'] ?? $request->header('Idempotency-Key')
        )], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.incident.view') || abort(403);
        $incident = TransportationIncident::query()->findOrFail($id);

        return response()->json(['data' => $this->incidents->snapshot($incident)]);
    }

    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.incident.manage') || abort(403);

        return response()->json(['data' => $this->incidents->acknowledge($id, $request->user()->id)]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.incident.manage') || abort(403);
        $validated = $request->validate(['user_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->incidents->assign($id, (int) $validated['user_id'])]);
    }

    public function escalate(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.incident.manage') || abort(403);
        $validated = $request->validate(['reason' => ['required', 'string'], 'to_user_id' => ['nullable', 'integer']]);
        $incident = TransportationIncident::query()->findOrFail($id);

        return response()->json(['data' => $this->incidents->escalate($incident, $validated['to_user_id'] ?? null, $validated['reason'])]);
    }

    public function action(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.incident.manage') || abort(403);
        $validated = $request->validate(['action_type' => ['required', 'string'], 'description' => ['nullable', 'string']]);
        $incident = TransportationIncident::query()->findOrFail($id);

        return response()->json(['data' => $this->incidents->addAction($incident, $validated)], 201);
    }

    public function attach(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.incident.report') || abort(403);
        $validated = $request->validate([
            'file_path' => ['required', 'string'],
            'attachment_type' => ['nullable', 'string'],
            'caption' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->incidents->attach($id, $validated)], 201);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.incident.manage') || abort(403);
        $validated = $request->validate(['resolution' => ['required', 'string']]);

        return response()->json(['data' => $this->incidents->resolve($id, $validated['resolution'], $request->user()->id)]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.incident.manage') || abort(403);
        $validated = $request->validate(['notes' => ['required', 'string']]);

        return response()->json(['data' => $this->incidents->review($id, $validated['notes'], $request->user()->id)]);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.incident.manage') || abort(403);

        return response()->json(['data' => $this->incidents->close($id, $request->user()->id)]);
    }

    /** Alur mogok: penggantian kendaraan/pengemudi + pemindahan penumpang (§49). */
    public function breakdown(Request $request, int $tripId): JsonResponse
    {
        $request->user()->can('transportation.incident.manage') || $request->user()->can('transportation.trip.dispatch') || abort(403);
        $validated = $request->validate([
            'replacement_vehicle_id' => ['nullable', 'integer'],
            'replacement_driver_profile_id' => ['nullable', 'integer'],
            'severity' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
            'location_description' => ['nullable', 'string'],
            'transfer_passengers' => ['nullable', 'boolean'],
        ]);
        $trip = Trip::query()->findOrFail($tripId);

        return response()->json(['data' => $this->incidents->handleBreakdown($trip, $validated)]);
    }
}
