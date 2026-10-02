<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\SchedulingService;
use App\Modules\SchoolOperations\Transportation\Models\{ScheduleException, TransportationSchedule};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Jadwal, pengecualian, kalender sekolah, dan pembentukan trip harian (§29, §53). */
class ScheduleController extends Controller
{
    public function __construct(private readonly SchedulingService $schedules)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('transportation.route.view') || abort(403);

        return response()->json(['data' => TransportationSchedule::query()
            ->when($request->input('route_id'), fn ($q, $routeId) => $q->where('route_id', $routeId))
            ->when($request->input('direction'), fn ($q, $direction) => $q->where('direction', strtoupper($direction)))
            ->orderBy('code')->get()->toArray()]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('transportation.route.manage') || abort(403);
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string'],
            'route_id' => ['required', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'schedule_type' => ['nullable', 'string'],
            'direction' => ['nullable', 'string'],
            'days_of_week' => ['nullable', 'array'],
            'valid_from' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date'],
            'specific_dates' => ['nullable', 'array'],
            'departure_time' => ['required'],
            'expected_arrival_time' => ['nullable'],
            'lead_minutes' => ['nullable', 'integer'],
            'default_vehicle_id' => ['nullable', 'integer'],
            'default_driver_profile_id' => ['nullable', 'integer'],
            'holiday_exception' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->schedules->createSchedule($validated)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.route.manage') || abort(403);
        $schedule = TransportationSchedule::query()->findOrFail($id);

        return response()->json(['data' => $this->schedules->updateSchedule($schedule, $request->all())]);
    }

    public function addException(Request $request): JsonResponse
    {
        $request->user()->can('transportation.route.manage') || abort(403);
        $validated = $request->validate([
            'schedule_id' => ['nullable', 'integer'],
            'route_id' => ['nullable', 'integer'],
            'exception_date' => ['required', 'date'],
            'exception_type' => ['required', 'string'],
            'override_departure_time' => ['nullable'],
            'override_arrival_time' => ['nullable'],
            'source' => ['nullable', 'string'],
            'cancel_trips' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->schedules->addException($validated)], 201);
    }

    public function exceptions(Request $request): JsonResponse
    {
        $request->user()->can('transportation.route.view') || abort(403);

        return response()->json(['data' => ScheduleException::query()
            ->when($request->input('from'), fn ($q, $from) => $q->whereDate('exception_date', '>=', $from))
            ->when($request->input('to'), fn ($q, $to) => $q->whereDate('exception_date', '<=', $to))
            ->orderBy('exception_date')->get()->toArray()]);
    }

    /** Sinkronisasi kalender akademik → pengecualian jadwal (§53). */
    public function syncAcademicCalendar(Request $request): JsonResponse
    {
        $request->user()->can('transportation.route.manage') || abort(403);

        return response()->json(['data' => $this->schedules->syncAcademicCalendar($request->input('from'), $request->input('to'))]);
    }

    /** Bentuk trip untuk sebuah tanggal (idempoten: trip yang ada dikembalikan). */
    public function generateTrips(Request $request): JsonResponse
    {
        $request->user()->can('transportation.trip.manage') || abort(403);
        $validated = $request->validate(['date' => ['required', 'date']]);

        return response()->json(['data' => $this->schedules->generateTrips($validated['date'], $request->only(['route_id', 'direction', 'campus_id', 'schedule_id']))]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $request->user()->can('transportation.trip.view') || abort(403);
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        return response()->json(['data' => $this->schedules->calendar($validated['from'], $validated['to'], $request->only(['route_id', 'campus_id']))]);
    }
}
