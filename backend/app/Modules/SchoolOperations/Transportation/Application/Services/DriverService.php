<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\{EmployeeGatewayInterface, NotificationGatewayInterface};
use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Domain\TransportStatus;
use App\Modules\SchoolOperations\Transportation\Models\{
    DriverProfile, DriverLicense, DriverQualification, DriverTrainingRecord,
    DriverAvailabilityWindow, DriverAssignment, Trip
};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengemudi: profil berpijak pada pegawai HRD (§11), SIM (§12), kualifikasi &
 * pelatihan (§13), ketersediaan (§14), dan penugasan termasuk pengemudi
 * pengganti (§15/§51) dengan validasi anti double-book (§87 Rule 3).
 */
class DriverService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly EmployeeGatewayInterface $employees,
        private readonly NotificationGatewayInterface $notifications,
    ) {
    }

    // ── Profil ─────────────────────────────────────────────────────────────

    /** Buat/segarkan profil pengemudi dari data pegawai HRD (tanpa duplikasi). */
    public function syncFromEmployee(int $employeeId, array $overrides = []): DriverProfile
    {
        $employee = $this->employees->find($employeeId);
        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pegawai tidak ditemukan pada modul HRD.',
            ]);
        }

        $profile = DriverProfile::query()->firstOrNew(['employee_id' => $employeeId]);
        $profile->fill(array_merge([
            'user_id' => $employee['user_id'] ?? null,
            'driver_code' => $profile->driver_code ?? ('DRV-'.str_pad((string) ($employee['id'] ?? $employeeId), 4, '0', STR_PAD_LEFT)),
            'full_name' => $employee['full_name'] ?? null,
            'phone' => $employee['phone'] ?? null,
            'employment_status' => $employee['employment_status'] ?? null,
        ], $overrides));

        if (! $profile->driver_status) {
            $profile->driver_status = 'AVAILABLE';
        }

        $profile->save();

        $this->context->audit('DriverProfileSynced', $profile, ['employee_id' => $employeeId]);

        return $profile;
    }

    public function setDriverStatus(DriverProfile $driver, string $status, ?string $reason = null): DriverProfile
    {
        $status = strtoupper($status);
        if (! in_array($status, TransportStatus::DRIVER_STATUSES, true)) {
            throw ValidationException::withMessages(['driver_status' => 'Status pengemudi tidak dikenal: '.$status]);
        }

        $previous = $driver->driver_status;
        $driver->forceFill(['driver_status' => $status])->save();

        $this->context->audit('DriverStatusChanged', $driver, ['from' => $previous, 'to' => $status, 'reason' => $reason]);

        if (in_array($status, TransportStatus::DRIVER_BLOCKING_STATUSES, true)) {
            $this->context->publish('DriverUnavailable', $driver, ['status' => $status, 'reason' => $reason]);
        }

        return $driver->refresh();
    }

    // ── SIM (§12) ──────────────────────────────────────────────────────────

    public function addLicense(DriverProfile $driver, array $payload): DriverLicense
    {
        $license = DriverLicense::query()->create([
            'driver_profile_id' => $driver->id,
            'license_type' => strtoupper((string) $payload['license_type']),
            'license_number' => (string) $payload['license_number'],
            'issue_date' => $payload['issue_date'] ?? null,
            'expiry_date' => $payload['expiry_date'] ?? null,
            'issuing_authority' => $payload['issuing_authority'] ?? null,
            'is_primary' => (bool) ($payload['is_primary'] ?? true),
            'file_path' => $payload['file_path'] ?? null,
        ]);

        if ($license->is_primary) {
            $driver->forceFill([
                'license_type' => $license->license_type,
                'license_number' => $license->license_number,
                'license_expiry' => $license->expiry_date,
            ])->save();
        }

        $this->context->audit('DriverLicenseAdded', $license, ['license_type' => $license->license_type]);

        return $license;
    }

    public function verifyLicense(DriverLicense $license, ?int $actorId = null): DriverLicense
    {
        $expired = $license->expiry_date && $license->expiry_date->isPast();
        $license->forceFill([
            'verified' => true,
            'verified_by' => $actorId,
            'verified_at' => now(),
            'status' => $expired ? 'EXPIRED' : 'ACTIVE',
        ])->save();

        return $license->refresh();
    }

    /** SIM valid & sesuai jenis kendaraan (§12/§87 Rule 2). */
    public function licenseCheck(DriverProfile $driver, string $vehicleType): array
    {
        $licenses = DriverLicense::query()->where('driver_profile_id', $driver->id)->get();
        if ($licenses->isEmpty() && $driver->license_type) {
            $licenses = collect([(object) [
                'license_type' => $driver->license_type,
                'license_number' => $driver->license_number,
                'expiry_date' => $driver->license_expiry,
            ]]);
        }

        if ($licenses->isEmpty()) {
            return ['valid' => false, 'reason' => 'Pengemudi belum memiliki data SIM.'];
        }

        $suitable = null;
        $expired = null;
        foreach ($licenses as $license) {
            $expiry = $license->expiry_date ?? null;
            $isExpired = $expiry !== null && \Illuminate\Support\Carbon::parse($expiry)->isPast();
            if ($isExpired) {
                $expired ??= $license;
                continue;
            }
            if (TransportStatus::licenseSuitableForVehicle(strtoupper((string) $license->license_type), strtoupper($vehicleType))) {
                $suitable = $license;
                break;
            }
        }

        if ($suitable) {
            return ['valid' => true, 'license_type' => $suitable->license_type, 'expiry_date' => $suitable->expiry_date, 'vehicle_type' => $vehicleType];
        }

        if ($expired) {
            return ['valid' => false, 'reason' => 'SIM '.$expired->license_type.' sudah kedaluwarsa.', 'expired_license' => $expired->license_type];
        }

        return [
            'valid' => false,
            'reason' => 'Kelas SIM yang dimiliki tidak sesuai untuk kendaraan '.$vehicleType.'.',
            'licenses' => $licenses->pluck('license_type')->all(),
        ];
    }

    // ── Kualifikasi & pelatihan (§13) ──────────────────────────────────────

    public function syncQualifications(DriverProfile $driver, array $items): array
    {
        $config = (array) config('transportation.qualification_types', []);
        $saved = [];
        foreach ($items as $item) {
            $type = strtoupper((string) ($item['qualification_type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $saved[] = DriverQualification::query()->updateOrCreate(
                ['driver_profile_id' => $driver->id, 'qualification_type' => $type],
                [
                    'level' => $item['level'] ?? null,
                    'certificate_number' => $item['certificate_number'] ?? null,
                    'issued_at' => $item['issued_at'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'status' => strtoupper((string) ($item['status'] ?? 'VALID')),
                    'file_path' => $item['file_path'] ?? null,
                ]
            );
        }

        $this->context->audit('DriverQualificationsSynced', $driver, ['items' => count($saved)]);

        return array_map(fn ($item): array => (array) $item->toArray(), $saved);
    }

    public function addTraining(DriverProfile $driver, array $payload): DriverTrainingRecord
    {
        $record = DriverTrainingRecord::query()->create(array_intersect_key($payload, array_flip([
            'training_name', 'training_type', 'provider', 'started_at', 'completed_at', 'result',
            'score', 'valid_until', 'certificate_number', 'file_path',
        ])) + [
            'driver_profile_id' => $driver->id,
            'recorded_by' => $this->context->actorId(),
        ]);

        $this->context->audit('DriverTrainingRecorded', $record, ['training' => $record->training_name]);

        return $record;
    }

    /** Kualifikasi wajib yang belum dimiliki / kedaluwarsa. */
    public function qualificationCheck(DriverProfile $driver): array
    {
        $required = array_keys(array_filter(
            (array) config('transportation.qualification_types', []),
            fn (array $item): bool => (bool) ($item['required_for_dispatch'] ?? false)
        ));

        $owned = DriverQualification::query()
            ->where('driver_profile_id', $driver->id)
            ->where('status', 'VALID')
            ->where(fn ($query) => $query->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
            ->pluck('qualification_type')
            ->all();

        $missing = array_values(array_diff($required, $owned));

        return [
            'valid' => $missing === [],
            'required' => $required,
            'owned' => $owned,
            'missing' => $missing,
            'reason' => $missing === [] ? null : 'Kualifikasi wajib belum lengkap: '.implode(', ', $missing).'.',
        ];
    }

    // ── Ketersediaan & penugasan (§14/§15) ─────────────────────────────────

    public function addAvailability(DriverProfile $driver, array $payload): DriverAvailabilityWindow
    {
        $window = DriverAvailabilityWindow::query()->create([
            'driver_profile_id' => $driver->id,
            'date' => $payload['date'],
            'start_time' => $payload['start_time'],
            'end_time' => $payload['end_time'],
            'status' => strtoupper((string) ($payload['status'] ?? 'AVAILABLE')),
            'reason' => $payload['reason'] ?? null,
            'created_by' => $this->context->actorId(),
        ]);

        $this->context->audit('DriverAvailabilityRecorded', $window, ['status' => $window->status]);

        if (in_array($window->status, ['LEAVE', 'OFF_DUTY', 'SUSPENDED'], true)) {
            $driver->forceFill(['driver_status' => $window->status === 'LEAVE' ? 'LEAVE' : 'OFF_DUTY'])->save();
        }

        return $window;
    }

    /** Penugasan pengemudi ke trip dengan seluruh validasi (§15). */
    public function assignToTrip(Trip $trip, DriverProfile $driver, array $options = []): array
    {
        $role = strtoupper((string) ($options['role'] ?? 'PRIMARY'));
        $vehicleType = $options['vehicle_type'] ?? optional($trip->vehicle)->vehicle_type ?? 'BUS';
        $startsAt = $options['starts_at'] ?? $trip->planned_start_at ?? now();
        $endsAt = $options['ends_at'] ?? $trip->planned_end_at ?? now()->addHours(2);

        $eligibility = $this->eligibility($driver, [
            'vehicle_type' => $vehicleType,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'exclude_trip_id' => $trip->id,
            'date' => $trip->trip_date?->toDateString() ?? now()->toDateString(),
        ]);

        if (! $eligibility['eligible'] && ! ($options['allow_override'] ?? false)) {
            $this->context->audit('DriverAssignmentRejected', $trip, [
                'driver_profile_id' => $driver->id, 'blockers' => array_column($eligibility['blockers'], 'code'),
            ]);

            return ['assigned' => false, 'eligibility' => $eligibility, 'reason' => $eligibility['blockers'][0]['message'] ?? 'Pengemudi tidak memenuhi syarat.'];
        }

        $assignment = DriverAssignment::query()->create([
            'driver_profile_id' => $driver->id,
            'trip_id' => $trip->id,
            'vehicle_id' => $trip->vehicle_id,
            'assignment_role' => $role,
            'status' => 'ASSIGNED',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'replaces_assignment_id' => $options['replaces_assignment_id'] ?? null,
            'replacement_reason' => $options['reason'] ?? null,
            'approved_by' => $options['approved_by'] ?? null,
            'approved_at' => isset($options['approved_by']) ? now() : null,
            'assigned_by' => $this->context->actorId(),
            'validation' => $eligibility,
        ]);

        if (in_array($role, ['PRIMARY', 'TEMPORARY'], true)) {
            $trip->forceFill(['driver_profile_id' => $driver->id])->save();
        }

        $driver->forceFill(['driver_status' => 'ON_DUTY'])->save();

        $this->context->audit('DriverAssigned', $trip, [
            'driver_profile_id' => $driver->id, 'role' => $role, 'override' => ! $eligibility['eligible'],
        ]);
        $this->context->publish('DriverAssigned', $trip, [
            'trip_number' => $trip->trip_number, 'driver_profile_id' => $driver->id, 'role' => $role,
        ]);

        if ($driver->user_id) {
            $this->notifications->send([(int) $driver->user_id], [
                'title' => 'Penugasan perjalanan '.$trip->trip_number,
                'body' => 'Anda ditugaskan pada perjalanan '.$trip->trip_number.' ('.$trip->trip_date?->toDateString().').',
                'event' => 'TripAssignment',
            ]);
        }

        return ['assigned' => true, 'assignment_id' => $assignment->id, 'eligibility' => $eligibility, 'role' => $role];
    }

    /**
     * Kelayakan pengemudi: status, SIM, kualifikasi, ketersediaan, dan
     * bentrok jadwal dengan trip lain (§87 Rule 3).
     */
    public function eligibility(DriverProfile $driver, array $context = []): array
    {
        $blockers = [];
        $status = strtoupper((string) $driver->driver_status);

        if (TransportStatus::driverBlocksDispatch($status)) {
            $blockers[] = ['code' => 'DRIVER_STATUS', 'message' => 'Status pengemudi '.$status.' tidak dapat ditugaskan.', 'severity' => 'HIGH'];
        }

        if (! empty($context['vehicle_type'])) {
            $license = $this->licenseCheck($driver, (string) $context['vehicle_type']);
            if (! $license['valid']) {
                $blockers[] = ['code' => 'LICENSE', 'message' => $license['reason'], 'severity' => 'HIGH', 'license' => $license];
            }
        }

        $qualification = $this->qualificationCheck($driver);
        if (! $qualification['valid']) {
            $config = (array) config('transportation.dispatch.require_driver_qualification', true);
            $blockers[] = [
                'code' => 'QUALIFICATION',
                'message' => $qualification['reason'],
                'severity' => $config ? 'HIGH' : 'MEDIUM',
                'qualification' => $qualification,
            ];
        }

        $date = $context['date'] ?? now()->toDateString();
        $unavailable = DriverAvailabilityWindow::query()
            ->where('driver_profile_id', $driver->id)
            ->whereDate('date', $date)
            ->whereIn('status', ['LEAVE', 'OFF_DUTY', 'SUSPENDED'])
            ->get();
        foreach ($unavailable as $window) {
            $blockers[] = [
                'code' => 'AVAILABILITY',
                'message' => 'Pengemudi tercatat '.$window->status.' pada '.$date.' ('.$window->start_time.'-'.$window->end_time.').',
                'severity' => 'HIGH',
                'reason' => $window->reason,
            ];
        }

        if (! empty($context['starts_at']) && ! empty($context['ends_at'])) {
            $conflict = DriverAssignment::query()
                ->where('driver_profile_id', $driver->id)
                ->where('status', 'ASSIGNED')
                ->where('starts_at', '<', $context['ends_at'])
                ->where('ends_at', '>', $context['starts_at'])
                ->when($context['exclude_trip_id'] ?? null, fn ($q, $tripId) => $q->where('trip_id', '!=', $tripId))
                ->first();

            if ($conflict) {
                $blockers[] = [
                    'code' => 'DOUBLE_BOOKED',
                    'message' => 'Pengemudi sudah ditugaskan pada trip lain yang waktunya bertumpang tindih.',
                    'severity' => 'HIGH',
                    'conflict_assignment_id' => $conflict->id,
                    'conflict_trip_id' => $conflict->trip_id,
                ];
            }
        }

        return [
            'eligible' => $blockers === [],
            'driver_profile_id' => $driver->id,
            'driver_status' => $driver->driver_status,
            'license' => $this->licenseCheck($driver, (string) ($context['vehicle_type'] ?? 'BUS')),
            'qualification' => $qualification,
            'blockers' => $blockers,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    // ── Penggantian pengemudi (§51) ────────────────────────────────────────

    public function replaceDriver(Trip $trip, DriverProfile $replacement, string $reason, ?int $approvedBy = null): array
    {
        $original = DriverAssignment::query()
            ->where('trip_id', $trip->id)
            ->where('status', 'ASSIGNED')
            ->whereIn('assignment_role', ['PRIMARY', 'TEMPORARY'])
            ->first();

        if (! $original) {
            throw ValidationException::withMessages(['driver' => 'Tidak ada penugasan pengemudi aktif pada trip ini.']);
        }

        $result = $this->assignToTrip($trip, $replacement, [
            'role' => 'REPLACEMENT',
            'reason' => $reason,
            'replaces_assignment_id' => $original->id,
            'approved_by' => $approvedBy,
            'starts_at' => $original->starts_at,
            'ends_at' => $original->ends_at,
            'vehicle_type' => optional($trip->vehicle)->vehicle_type ?? 'BUS',
        ]);

        $original->forceFill(['status' => 'REPLACED'])->save();

        $previousDriver = DriverProfile::query()->find($original->driver_profile_id);
        if ($previousDriver) {
            $previousDriver->forceFill(['driver_status' => 'AVAILABLE'])->save();
        }

        $this->context->audit('DriverReplaced', $trip, [
            'original_driver_profile_id' => $original->driver_profile_id,
            'replacement_driver_profile_id' => $replacement->id,
            'reason' => $reason,
        ]);
        $this->context->publish('DriverReplaced', $trip, [
            'original' => $original->driver_profile_id, 'replacement' => $replacement->id, 'reason' => $reason,
        ]);

        return $result + ['original_driver_profile_id' => $original->driver_profile_id];
    }

    public function availableDrivers(array $filters = []): array
    {
        $date = $filters['date'] ?? now()->toDateString();

        return DriverProfile::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('driver_status', strtoupper($status)))
            ->when($filters['campus_id'] ?? null, fn ($q, $campus) => $q->where('campus_id', $campus))
            ->orderBy('driver_code')
            ->get()
            ->map(fn (DriverProfile $driver): array => [
                'id' => $driver->id,
                'driver_code' => $driver->driver_code,
                'full_name' => $driver->full_name,
                'driver_status' => $driver->driver_status,
                'license_type' => $driver->license_type,
                'license_expiry' => $driver->license_expiry?->toDateString(),
                'license_valid' => ! ($driver->license_expiry && $driver->license_expiry->isPast()),
                'qualifications' => $driver->qualifications()->pluck('qualification_type')->all(),
                'trips_today' => DB::table('trips')->where('driver_profile_id', $driver->id)->whereDate('trip_date', $date)->count(),
            ])
            ->values()
            ->all();
    }
}
