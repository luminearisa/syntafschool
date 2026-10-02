<?php

namespace App\Modules\SchoolOperations\Transportation\Domain;

/**
 * Status, jenis, dan kategori modul transportasi.
 *
 * Nilai diambil dari konfigurasi agar institusi dapat menyesuaikan tanpa
 * mengubah kode; kelas ini hanya melakukan normalisasi dan validasi.
 */
final class TransportStatus
{
    // Vehicle
    public const VEHICLE_STATUSES = ['AVAILABLE', 'ASSIGNED', 'ON_TRIP', 'MAINTENANCE', 'INSPECTION', 'INACTIVE', 'RETIRED'];
    public const VEHICLE_BLOCKING_STATUSES = ['MAINTENANCE', 'INSPECTION', 'INACTIVE', 'RETIRED'];

    // Driver
    public const DRIVER_STATUSES = ['AVAILABLE', 'ON_DUTY', 'ON_TRIP', 'OFF_DUTY', 'LEAVE', 'SUSPENDED', 'INACTIVE'];
    public const DRIVER_BLOCKING_STATUSES = ['OFF_DUTY', 'LEAVE', 'SUSPENDED', 'INACTIVE'];

    // Trip
    public const TRIP_TERMINAL_STATUSES = ['COMPLETED', 'CANCELLED'];
    public const TRIP_ACTIVE_STATUSES = ['DISPATCHED', 'BOARDING', 'IN_TRANSIT', 'ARRIVED', 'DELAYED', 'INCIDENT'];

    // Request
    public const REQUEST_OPEN_STATUSES = ['SUBMITTED', 'VALIDATED', 'ON_WAITLIST'];

    public static function tripStatuses(): array
    {
        return (array) config('transportation.trip_statuses', []);
    }

    public static function boardingStatuses(): array
    {
        return (array) config('transportation.boarding_statuses', []);
    }

    public static function dropoffStatuses(): array
    {
        return (array) config('transportation.dropoff_statuses', []);
    }

    public static function delayReasons(): array
    {
        return (array) config('transportation.delay_reasons', []);
    }

    public static function incidentTypes(): array
    {
        return (array) config('transportation.incident_types', []);
    }

    public static function incidentSeverities(): array
    {
        return (array) config('transportation.incident_severities', []);
    }

    public static function attendanceMethods(): array
    {
        return (array) config('transportation.attendance_methods', []);
    }

    public static function costCategories(): array
    {
        return (array) config('transportation.cost_categories', []);
    }

    public static function licenseTypes(): array
    {
        return array_keys((array) config('transportation.license_types', []));
    }

    /**
     * Apakah kelas SIM cukup untuk jenis kendaraan tertentu?
     * Menggunakan peringkat bila pemetaan eksplisit tidak tersedia.
     */
    public static function licenseSuitableForVehicle(string $licenseType, string $vehicleType): bool
    {
        $licenses = (array) config('transportation.license_types', []);
        $context = $licenses[$licenseType] ?? null;
        if ($context === null) {
            return false;
        }

        if (in_array($vehicleType, (array) ($context['vehicle_types'] ?? []), true)) {
            return true;
        }

        $required = (array) config('transportation.vehicle_types.'.$vehicleType, []);
        $requiredLicense = $required['license_requirement'] ?? null;
        if ($requiredLicense === null) {
            return false;
        }

        return (int) ($context['rank'] ?? 0) >= (int) ($licenses[$requiredLicense]['rank'] ?? 99);
    }

    /** Status yang memblokir dispatch. */
    public static function vehicleBlocksDispatch(string $status): bool
    {
        return in_array($status, self::VEHICLE_BLOCKING_STATUSES, true);
    }

    public static function driverBlocksDispatch(string $status): bool
    {
        return in_array($status, self::DRIVER_BLOCKING_STATUSES, true);
    }
}
