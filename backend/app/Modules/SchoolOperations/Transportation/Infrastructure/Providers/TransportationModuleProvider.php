<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Providers;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\TransportationProviderInterface;
use App\Modules\SchoolOperations\Transportation\Models\{DriverProfile, TransportationAssignment, Trip, VehicleProfile};
use App\Modules\SchoolOperations\Transportation\Application\Services\{
    BoardingService, RouteService, TransportationAssignmentService, TransportationRequestService, TripService
};

/**
 * Kontrak transportasi untuk modul lain (§3): penugasan siswa, rute aktif,
 * trip siswa, status kendaraan/pengemudi, manifes, riwayat, dan permintaan.
 */
class TransportationModuleProvider implements TransportationProviderInterface
{
    public function __construct(
        private readonly RouteService $routes,
        private readonly TripService $trips,
        private readonly BoardingService $boarding,
        private readonly TransportationAssignmentService $assignments,
        private readonly TransportationRequestService $requests,
    ) {
    }

    public function getStudentTransportationAssignment(int $studentId): ?array
    {
        $profile = $this->assignments->profile($studentId);
        $assignments = $this->assignments->assignments(['student_id' => $studentId, 'status' => 'ACTIVE']);

        if ($profile === null && $assignments === []) {
            return null;
        }

        return [
            'student_id' => $studentId,
            'has_assignment' => $assignments !== [],
            'assignments' => $assignments,
            'profile' => $profile,
        ];
    }

    public function getActiveRoutes(array $filters = []): array
    {
        return $this->routes->activeRoutes($filters);
    }

    public function getStudentTrips(int $studentId, ?string $from = null, ?string $to = null): array
    {
        return $this->boarding->studentTodayStatus($studentId, $to ?? now()->toDateString())['trips'];
    }

    public function getVehicleStatus(int $vehicleId): ?array
    {
        $vehicle = VehicleProfile::query()->find($vehicleId);
        if (! $vehicle) {
            return null;
        }

        return [
            'id' => $vehicle->id,
            'vehicle_code' => $vehicle->vehicle_code,
            'registration_number' => $vehicle->registration_number,
            'status' => $vehicle->status,
            'ownership_type' => $vehicle->ownership_type,
            'capacity' => [
                'total' => (int) $vehicle->total_capacity,
                'student' => (int) $vehicle->student_capacity,
                'wheelchair' => (int) $vehicle->wheelchair_capacity,
            ],
            'odometer' => (float) $vehicle->odometer,
            'assigned_trips_today' => Trip::query()->where('vehicle_id', $vehicle->id)->whereDate('trip_date', now()->toDateString())->count(),
        ];
    }

    public function getDriverStatus(int $driverProfileId): ?array
    {
        $driver = DriverProfile::query()->find($driverProfileId);
        if (! $driver) {
            return null;
        }

        return [
            'id' => $driver->id,
            'driver_code' => $driver->driver_code,
            'full_name' => $driver->full_name,
            'status' => $driver->driver_status,
            'license_type' => $driver->license_type,
            'license_valid' => ! ($driver->license_expiry && $driver->license_expiry->isPast()),
            'assigned_trips_today' => Trip::query()->where('driver_profile_id', $driver->id)->whereDate('trip_date', now()->toDateString())->count(),
        ];
    }

    public function getTripManifest(int $tripId): ?array
    {
        $trip = Trip::query()->find($tripId);
        if (! $trip) {
            return null;
        }
        $trip->loadMissing(['route', 'vehicle', 'driver']);

        return $this->trips->tripSnapshot($trip);
    }

    public function getTripHistory(array $filters = []): array
    {
        return $this->trips->history($filters);
    }

    /** Permintaan transportasi dari modul lain (mis. kegiatan/lomba, §55). */
    public function request(array $payload): array
    {
        $payload['requested_by_type'] = $payload['requested_by_type'] ?? 'STAFF';
        $payload['request_type'] = $payload['request_type'] ?? 'SPECIAL';

        $result = $this->requests->submit($payload);

        if (($result['status'] ?? null) === 'VALIDATED') {
            $approved = $this->requests->approve((int) $result['request_id'], null, [
                'allow_override' => (bool) ($payload['allow_override'] ?? false),
            ]);
            $result['auto_approved'] = $approved['approved'] ?? false;
            $result['applied'] = $approved['applied'] ?? null;
        }

        return $result + ['reference' => $result['request_number'] ?? null];
    }

    public function cancel(string $reference): bool
    {
        $trip = Trip::query()->where('trip_number', $reference)->first();
        if ($trip && ! in_array($trip->status, ['COMPLETED', 'CANCELLED'], true)) {
            $this->trips->cancel($trip, 'Dibatalkan oleh modul pemanggil.', ['notify' => true]);

            return true;
        }

        $request = \App\Modules\SchoolOperations\Transportation\Models\TransportationRequest::query()
            ->where('request_number', $reference)->first();
        if ($request) {
            $this->requests->reject((int) $request->id, 'Dibatalkan oleh modul pemanggil.');

            return true;
        }

        return false;
    }

    public function isAvailable(): bool
    {
        return TransportationAssignment::query()->exists() || Trip::query()->exists() || VehicleProfile::query()->exists();
    }
}
