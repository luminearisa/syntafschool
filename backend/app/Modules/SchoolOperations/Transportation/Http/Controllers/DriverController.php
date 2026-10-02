<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\{DriverService, TransportationAnalyticsService};
use App\Modules\SchoolOperations\Transportation\Models\{DriverLicense, DriverProfile, Trip};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Pengemudi: profil HRD, SIM, kualifikasi, pelatihan, ketersediaan, penugasan (§11-§15, §51). */
class DriverController extends Controller
{
    public function __construct(
        private readonly DriverService $drivers,
        private readonly TransportationAnalyticsService $analytics,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('transportation.driver.view') || abort(403);

        return response()->json(['data' => $this->drivers->availableDrivers($request->only(['date', 'status', 'campus_id']))]);
    }

    public function syncFromEmployee(Request $request): JsonResponse
    {
        $request->user()->can('transportation.driver.manage') || abort(403);
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'campus_id' => ['nullable', 'integer'],
            'license_number' => ['nullable', 'string'],
            'license_type' => ['nullable', 'string'],
            'license_expiry' => ['nullable', 'date'],
        ]);

        $profile = $this->drivers->syncFromEmployee((int) $validated['employee_id'], array_filter([
            'campus_id' => $validated['campus_id'] ?? null,
            'license_number' => $validated['license_number'] ?? null,
            'license_type' => $validated['license_type'] ?? null,
            'license_expiry' => $validated['license_expiry'] ?? null,
        ]));

        return response()->json(['data' => $profile], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.driver.view') || abort(403);
        $driver = DriverProfile::query()->with(['licenses', 'qualifications', 'trainings'])->findOrFail($id);

        return response()->json(['data' => [
            'profile' => $driver->toArray(),
            'eligibility' => $this->drivers->eligibility($driver, ['vehicle_type' => $request->input('vehicle_type', 'BUS')]),
            'license_check' => $this->drivers->licenseCheck($driver, (string) $request->input('vehicle_type', 'BUS')),
            'qualification_check' => $this->drivers->qualificationCheck($driver),
            'today_trips' => Trip::query()->where('driver_profile_id', $driver->id)
                ->whereDate('trip_date', now()->toDateString())->get(['id', 'trip_number', 'status', 'planned_departure'])->toArray(),
        ]]);
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.driver.manage') || abort(403);
        $validated = $request->validate(['driver_status' => ['required', 'string'], 'reason' => ['nullable', 'string']]);
        $driver = DriverProfile::query()->findOrFail($id);

        return response()->json(['data' => $this->drivers->setDriverStatus($driver, $validated['driver_status'], $validated['reason'] ?? null)]);
    }

    public function addLicense(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.driver.manage') || abort(403);
        $validated = $request->validate([
            'license_type' => ['required', 'string'],
            'license_number' => ['required', 'string'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'issuing_authority' => ['nullable', 'string'],
            'is_primary' => ['nullable', 'boolean'],
            'file_path' => ['nullable', 'string'],
        ]);
        $driver = DriverProfile::query()->findOrFail($id);

        return response()->json(['data' => $this->drivers->addLicense($driver, $validated)], 201);
    }

    public function verifyLicense(Request $request, int $licenseId): JsonResponse
    {
        $request->user()->can('transportation.driver.manage') || abort(403);
        $license = DriverLicense::query()->findOrFail($licenseId);

        return response()->json(['data' => $this->drivers->verifyLicense($license, $request->user()->id)]);
    }

    public function syncQualifications(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.driver.manage') || abort(403);
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.qualification_type' => ['required', 'string'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.status' => ['nullable', 'string'],
        ]);
        $driver = DriverProfile::query()->findOrFail($id);

        return response()->json(['data' => $this->drivers->syncQualifications($driver, $validated['items'])]);
    }

    public function addTraining(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.driver.manage') || abort(403);
        $validated = $request->validate([
            'training_name' => ['required', 'string'],
            'training_type' => ['nullable', 'string'],
            'provider' => ['nullable', 'string'],
            'started_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'result' => ['nullable', 'string'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'valid_until' => ['nullable', 'date'],
            'certificate_number' => ['nullable', 'string'],
        ]);
        $driver = DriverProfile::query()->findOrFail($id);

        return response()->json(['data' => $this->drivers->addTraining($driver, $validated)], 201);
    }

    public function addAvailability(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.driver.manage') || abort(403);
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'start_time' => ['required'],
            'end_time' => ['required'],
            'status' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
        ]);
        $driver = DriverProfile::query()->findOrFail($id);

        return response()->json(['data' => $this->drivers->addAvailability($driver, $validated)], 201);
    }

    public function assignToTrip(Request $request, int $tripId): JsonResponse
    {
        $request->user()->can('transportation.driver.assign') || abort(403);
        $validated = $request->validate([
            'driver_profile_id' => ['required', 'integer'],
            'role' => ['nullable', 'string'],
            'allow_override' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string'],
        ]);
        $trip = Trip::query()->findOrFail($tripId);
        $driver = DriverProfile::query()->findOrFail((int) $validated['driver_profile_id']);

        $result = $this->drivers->assignToTrip($trip, $driver, [
            'role' => $validated['role'] ?? 'PRIMARY',
            'allow_override' => (bool) ($validated['allow_override'] ?? false),
            'reason' => $validated['reason'] ?? null,
            'vehicle_type' => $trip->vehicle?->vehicle_type ?? 'BUS',
        ]);

        return response()->json(['data' => $result], ($result['assigned'] ?? false) ? 201 : 422);
    }

    public function replaceDriver(Request $request, int $tripId): JsonResponse
    {
        $request->user()->can('transportation.driver.assign') || abort(403);
        $validated = $request->validate([
            'driver_profile_id' => ['required', 'integer'],
            'reason' => ['required', 'string'],
        ]);
        $trip = Trip::query()->findOrFail($tripId);
        $driver = DriverProfile::query()->findOrFail((int) $validated['driver_profile_id']);

        return response()->json(['data' => $this->drivers->replaceDriver($trip, $driver, $validated['reason'], $request->user()->id)]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $request->user()->can('transportation.analytics.view') || abort(403);

        return response()->json(['data' => $this->analytics->driverAnalytics($request->input('from'), $request->input('to'))]);
    }
}
