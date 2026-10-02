<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/**
 * Kontrak transportasi untuk modul lain (Activity, Boarding, Clinic, dll).
 * Semua metode mengembalikan array siap-pakai; `isAvailable()` menyatakan
 * apakah modul benar-benar memiliki data (bukan sekadar terpasang).
 */
interface TransportationProviderInterface
{
    public function getStudentTransportationAssignment(int $studentId): ?array;

    public function getActiveRoutes(array $filters = []): array;

    public function getStudentTrips(int $studentId, ?string $from = null, ?string $to = null): array;

    public function getVehicleStatus(int $vehicleId): ?array;

    public function getDriverStatus(int $driverProfileId): ?array;

    public function getTripManifest(int $tripId): ?array;

    public function getTripHistory(array $filters = []): array;

    public function request(array $payload): array;

    public function cancel(string $reference): bool;

    public function isAvailable(): bool;
}
