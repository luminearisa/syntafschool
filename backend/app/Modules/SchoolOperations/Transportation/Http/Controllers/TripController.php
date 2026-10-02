<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\{BoardingService, ManifestService, TripService};
use App\Modules\SchoolOperations\Transportation\Models\{Trip, TripPassenger, TripStop};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Trip: pembentukan, dispatch, operasi halte, boarding/drop-off, keterlambatan, penutupan (§30-§44, §52). */
class TripController extends Controller
{
    public function __construct(
        private readonly TripService $trips,
        private readonly ManifestService $manifest,
        private readonly BoardingService $boarding,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('transportation.trip.view') || abort(403);

        return response()->json(['data' => $this->trips->todayTrips($request->only([
            'date', 'campus_id', 'route_id', 'vehicle_id', 'driver_profile_id', 'status',
        ]))]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('transportation.trip.manage') || abort(403);
        $validated = $request->validate([
            'route_id' => ['required', 'integer'],
            'trip_date' => ['nullable', 'date'],
            'planned_departure' => ['nullable'],
            'planned_arrival' => ['nullable'],
            'direction' => ['nullable', 'string'],
            'trip_type' => ['nullable', 'string'],
            'service_id' => ['nullable', 'integer'],
            'event_reference_id' => ['nullable', 'integer'],
            'event_reference_type' => ['nullable', 'string'],
            'planned_passengers' => ['nullable', 'integer'],
            'passengers' => ['nullable', 'array'],
        ]);

        return response()->json(['data' => $this->trips->tripSnapshot($this->trips->createAdHoc($validated))], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $trip = Trip::query()->findOrFail($id);
        $this->authorizeTrip($request, $trip);

        return response()->json(['data' => $this->trips->tripSnapshot($trip)]);
    }

    public function manifest(Request $request, int $id): JsonResponse
    {
        $trip = Trip::query()->findOrFail($id);
        $this->authorizeTrip($request, $trip);

        return response()->json(['data' => $this->manifest->manifestSnapshot($trip)]);
    }

    public function regenerateManifest(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.trip.manage') || abort(403);
        $trip = Trip::query()->findOrFail($id);

        return response()->json(['data' => $this->manifest->manifestSnapshot(
            tap($trip, fn () => $this->manifest->generate($trip, ['rebuild' => true]))
        )]);
    }

    public function assignVehicle(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.trip.dispatch') || abort(403);
        $validated = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'role' => ['nullable', 'string'],
            'allow_override' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string'],
        ]);
        $trip = Trip::query()->findOrFail($id);
        $vehicle = \App\Modules\SchoolOperations\Transportation\Models\VehicleProfile::query()->findOrFail((int) $validated['vehicle_id']);

        $result = $this->trips->assignVehicle($trip, $vehicle, $validated);

        return response()->json(['data' => $result], ($result['assigned'] ?? false) ? 201 : 422);
    }

    /** Penggantian kendaraan (§50) — mis. mogok di tengah perjalanan. */
    public function replaceVehicle(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.trip.dispatch') || abort(403);
        $validated = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'reason' => ['required', 'string'],
            'transfer_passengers' => ['nullable', 'boolean'],
        ]);
        $trip = Trip::query()->findOrFail($id);
        $vehicle = \App\Modules\SchoolOperations\Transportation\Models\VehicleProfile::query()->findOrFail((int) $validated['vehicle_id']);

        return response()->json(['data' => $this->trips->replaceVehicle(
            $trip, $vehicle, $validated['reason'], (bool) ($validated['transfer_passengers'] ?? true), $request->user()->id
        )]);
    }

    /** Validasi gerbang dispatch tanpa mengubah status (§32). */
    public function validateDispatch(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.dispatch.view') || abort(403);
        $trip = Trip::query()->findOrFail($id);

        return response()->json(['data' => $this->trips->validateDispatch($trip)]);
    }

    public function dispatch(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.trip.dispatch') || abort(403);
        $trip = Trip::query()->findOrFail($id);
        $result = $this->trips->dispatch($trip, [
            'allow_override' => (bool) $request->input('allow_override', false),
            'reason' => $request->input('reason'),
        ]);

        return response()->json(['data' => $result], ($result['dispatched'] ?? false) ? 200 : 422);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.trip.operate') || $request->user()->can('transportation.trip.manage') || abort(403);
        $trip = Trip::query()->findOrFail($id);

        return response()->json(['data' => $this->trips->start($trip, $request->all(), $request->header('Idempotency-Key') ?? $request->input('idempotency_key'))]);
    }

    public function arriveStop(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.trip.operate') || $request->user()->can('transportation.trip.manage') || abort(403);
        $validated = $request->validate(['stop_id' => ['required', 'integer']]);
        $trip = Trip::query()->findOrFail($id);
        $stop = TripStop::query()->findOrFail((int) $validated['stop_id']);

        return response()->json(['data' => $this->trips->arriveStop($trip, $stop, $request->all())]);
    }

    public function departStop(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.trip.operate') || $request->user()->can('transportation.trip.manage') || abort(403);
        $validated = $request->validate(['stop_id' => ['required', 'integer']]);
        $trip = Trip::query()->findOrFail($id);
        $stop = TripStop::query()->findOrFail((int) $validated['stop_id']);

        return response()->json(['data' => $this->trips->departStop($trip, $stop, $request->all())]);
    }

    public function board(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.boarding.record') || abort(403);
        $validated = $request->validate([
            'trip_passenger_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'stop_id' => ['nullable', 'integer'],
            'method' => ['nullable', 'string'],
            'card_id' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string'],
            'occurred_at' => ['nullable'],
            'allow_override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string'],
            'offline_sync' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->boarding->board(
            $validated + ['trip_id' => $id],
            $validated['idempotency_key'] ?? $request->header('Idempotency-Key')
        )], 201);
    }

    public function dropoff(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.boarding.record') || abort(403);
        $validated = $request->validate([
            'trip_passenger_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'stop_id' => ['nullable', 'integer'],
            'method' => ['nullable', 'string'],
            'authorized_pickup_person_id' => ['nullable', 'integer'],
            'picked_up_by_name' => ['nullable', 'string'],
            'authorized_correction' => ['nullable', 'boolean'],
            'allow_override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string'],
            'occurred_at' => ['nullable'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->boarding->dropoff(
            $validated + ['trip_id' => $id],
            $validated['idempotency_key'] ?? $request->header('Idempotency-Key')
        )], 201);
    }

    public function noShow(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.boarding.record') || abort(403);
        $validated = $request->validate([
            'trip_passenger_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->boarding->markNoShow($validated + ['trip_id' => $id], $validated['idempotency_key'] ?? null)]);
    }

    public function delay(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.trip.operate') || $request->user()->can('transportation.trip.manage') || abort(403);
        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string'],
            'stop_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'notify' => ['nullable', 'boolean'],
        ]);
        $trip = Trip::query()->findOrFail($id);

        return response()->json(['data' => $this->trips->reportDelay(
            $trip, (int) $validated['minutes'], $validated['reason'], $validated['stop_id'] ?? null, $validated
        )]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.trip.operate') || $request->user()->can('transportation.trip.close') || abort(403);
        $trip = Trip::query()->findOrFail($id);

        return response()->json(['data' => $this->trips->complete($trip, $request->all(), $request->header('Idempotency-Key') ?? $request->input('idempotency_key'))]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.trip.manage') || abort(403);
        $validated = $request->validate(['reason' => ['required', 'string'], 'notify' => ['nullable', 'boolean']]);
        $trip = Trip::query()->findOrFail($id);

        return response()->json(['data' => $this->trips->cancel($trip, $validated['reason'], $validated)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.trip.manage') || abort(403);
        $trip = Trip::query()->findOrFail($id);

        return response()->json(['data' => $this->trips->tripSnapshot($this->trips->updateTrip($trip, $request->all()))]);
    }

    public function history(Request $request): JsonResponse
    {
        $request->user()->can('transportation.trip.view') || abort(403);

        return response()->json(['data' => $this->trips->history($request->only([
            'from', 'to', 'route_id', 'vehicle_id', 'driver_profile_id', 'status', 'limit',
        ]))]);
    }

    /** Papan dispatch (§76/§96). */
    public function dispatchBoard(Request $request): JsonResponse
    {
        $request->user()->can('transportation.dispatch.view') || abort(403);

        return response()->json(['data' => $this->trips->dispatchBoard($request->only([
            'date', 'campus_id', 'route_id', 'vehicle_id', 'driver_profile_id', 'status',
        ]))]);
    }

    /** Operasi halte: daftar halte + jumlah penumpang (§41). */
    public function stops(Request $request, int $id): JsonResponse
    {
        $trip = Trip::query()->findOrFail($id);
        $this->authorizeTrip($request, $trip);

        return response()->json(['data' => $this->boarding->tripStopsWithCounts($trip->id)]);
    }

    public function addPassenger(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.manifest.view') || abort(403);
        $trip = Trip::query()->findOrFail($id);

        return response()->json(['data' => $this->manifest->addPassenger($trip, $request->all())], 201);
    }

    public function passenger(Request $request, int $passengerId): JsonResponse
    {
        $passenger = TripPassenger::query()->findOrFail($passengerId);
        $trip = Trip::query()->findOrFail($passenger->trip_id);
        $this->authorizeTrip($request, $trip);

        return response()->json(['data' => $passenger->toArray() + [
            'history' => [
                'boarding_events' => \App\Modules\SchoolOperations\Transportation\Models\BoardingEvent::query()
                    ->where('trip_passenger_id', $passenger->id)->get()->toArray(),
                'dropoff_events' => \App\Modules\SchoolOperations\Transportation\Models\DropoffEvent::query()
                    ->where('trip_passenger_id', $passenger->id)->get()->toArray(),
            ],
        ]]);
    }

    /** Pengemudi hanya dapat mengakses trip yang ditugaskan kepadanya (§87 Rule 15). */
    private function authorizeTrip(Request $request, Trip $trip): void
    {
        $scope = app(\App\Modules\SchoolOperations\Transportation\Application\Services\TransportationScopeService::class);
        if (! $scope->canOperateTrip($request->user(), $trip)) {
            abort(403, 'Anda tidak berhak mengakses trip ini.');
        }
    }
}
