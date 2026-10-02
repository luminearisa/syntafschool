<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\ParentGatewayInterface;
use App\Modules\SchoolOperations\Transportation\Models\{DriverProfile, Trip, TransportationAssignment};
use Illuminate\Database\Eloquent\Builder;

/**
 * Cakupan akses transportasi (§4/§91):
 * - orang tua hanya data anaknya (§87 Rule 14),
 * - pengemudi hanya trip yang ditugaskan kepadanya (§87 Rule 15),
 * - staf dibatasi kampus/koridor sesuai peran,
 * - auditor hanya baca.
 */
class TransportationScopeService
{
    public const PERMISSIONS = [
        'transportation.view', 'transportation.dashboard.view', 'transportation.dispatch.view', 'transportation.dispatch.manage',
        'transportation.fleet.view', 'transportation.fleet.manage', 'transportation.fleet.documents', 'transportation.fleet.fuel',
        'transportation.driver.view', 'transportation.driver.manage', 'transportation.driver.assign',
        'transportation.route.view', 'transportation.route.manage', 'transportation.route.approve',
        'transportation.student.view', 'transportation.student.manage', 'transportation.request.view',
        'transportation.request.create', 'transportation.request.approve', 'transportation.waitlist.manage',
        'transportation.trip.view', 'transportation.trip.manage', 'transportation.trip.dispatch',
        'transportation.trip.operate', 'transportation.trip.close', 'transportation.manifest.view',
        'transportation.lostfound.view',
        'transportation.boarding.record', 'transportation.boarding.override',
        'transportation.inspection.view', 'transportation.inspection.perform',
        'transportation.incident.view', 'transportation.incident.report', 'transportation.incident.manage',
        'transportation.lostfound.view', 'transportation.lostfound.manage',
        'transportation.cost.view', 'transportation.cost.manage', 'transportation.analytics.view',
        'transportation.report.view', 'transportation.audit.view', 'transportation.settings.manage', 'transportation.notification.send',
        'transportation.adjustment.approve', 'transportation.portal.view', 'transportation.driver_app.use',
    ];

    public const ROLE_PERMISSIONS = [
        'TRANSPORTATION_ADMIN' => '*',
        'TRANSPORTATION_MANAGER' => [
            'transportation.view', 'transportation.dashboard.view', 'transportation.dispatch.view', 'transportation.dispatch.manage',
            'transportation.fleet.view', 'transportation.fleet.manage', 'transportation.fleet.documents', 'transportation.fleet.fuel',
            'transportation.driver.view', 'transportation.driver.manage', 'transportation.driver.assign',
            'transportation.route.view', 'transportation.route.manage', 'transportation.route.approve',
            'transportation.student.view', 'transportation.student.manage', 'transportation.request.view',
            'transportation.request.approve', 'transportation.waitlist.manage',
            'transportation.trip.view', 'transportation.trip.manage', 'transportation.trip.dispatch', 'transportation.trip.close',
            'transportation.manifest.view', 'transportation.inspection.view', 'transportation.inspection.perform',
            'transportation.incident.view', 'transportation.incident.manage', 'transportation.lostfound.manage',
            'transportation.cost.view', 'transportation.cost.manage', 'transportation.analytics.view',
            'transportation.report.view', 'transportation.adjustment.approve', 'transportation.settings.manage',
        ],
        'TRANSPORTATION_DISPATCHER' => [
            'transportation.view', 'transportation.dashboard.view', 'transportation.dispatch.view', 'transportation.dispatch.manage',
            'transportation.trip.view', 'transportation.trip.manage', 'transportation.trip.dispatch', 'transportation.manifest.view',
            'transportation.driver.view', 'transportation.driver.assign', 'transportation.fleet.view',
            'transportation.route.view', 'transportation.incident.view', 'transportation.incident.report',
            'transportation.notification.send',
        ],
        'FLEET_MANAGER' => [
            'transportation.view', 'transportation.fleet.view', 'transportation.fleet.manage', 'transportation.fleet.documents',
            'transportation.fleet.fuel', 'transportation.inspection.view', 'transportation.inspection.perform',
            'transportation.cost.view', 'transportation.analytics.view', 'transportation.report.view',
        ],
        'FLEET_COORDINATOR' => [
            'transportation.view', 'transportation.fleet.view', 'transportation.fleet.fuel',
            'transportation.inspection.view', 'transportation.inspection.perform',
        ],
        'DRIVER' => ['transportation.driver_app.use', 'transportation.trip.operate', 'transportation.boarding.record',
            'transportation.incident.report', 'transportation.inspection.perform', 'transportation.manifest.view'],
        'DRIVER_SUPERVISOR' => ['transportation.view', 'transportation.driver.view', 'transportation.driver.manage',
            'transportation.driver.assign', 'transportation.trip.view', 'transportation.incident.view', 'transportation.incident.manage',
            'transportation.inspection.view', 'transportation.analytics.view'],
        'TRANSPORTATION_AUDITOR' => ['transportation.view', 'transportation.audit.view', 'transportation.analytics.view',
            'transportation.report.view', 'transportation.trip.view', 'transportation.incident.view', 'transportation.cost.view'],
        'MAINTENANCE_STAFF' => ['transportation.view', 'transportation.fleet.view', 'transportation.inspection.view'],
        'FINANCE' => ['transportation.view', 'transportation.cost.view', 'transportation.report.view'],
        'SECURITY' => ['transportation.view', 'transportation.dispatch.view', 'transportation.incident.report'],
        'EVENT_COORDINATOR' => ['transportation.view', 'transportation.request.create', 'transportation.request.view', 'transportation.trip.view'],
        'TEACHER' => ['transportation.view'],
        'BOARDING_STAFF' => ['transportation.view', 'transportation.student.view', 'transportation.trip.view'],
        'SCHOOL_ADMIN' => ['transportation.view', 'transportation.dashboard.view', 'transportation.student.view',
            'transportation.request.view', 'transportation.request.approve', 'transportation.trip.view', 'transportation.report.view'],
        'STUDENT' => ['transportation.portal.view'],
        'PARENT' => ['transportation.portal.view', 'transportation.request.create'],
    ];

    public function __construct(private readonly ParentGatewayInterface $parents)
    {
    }

    public function isDriver(mixed $user): bool
    {
        return $this->driverProfile($user) !== null;
    }

    public function driverProfile(mixed $user): ?DriverProfile
    {
        if (! $user) {
            return null;
        }

        $employeeId = null;
        if (method_exists($user, 'getKey')) {
            $profile = DriverProfile::query()->where('user_id', $user->getKey())->first();
            if ($profile) {
                return $profile;
            }
        }

        return null;
    }

    /** Orang tua hanya dapat melihat data anaknya sendiri (§87 Rule 14). */
    public function canAccessStudent(mixed $user, int $studentId): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->can('transportation.view') && ! $this->isParentOnly($user)) {
            return true;
        }

        return $this->parents->canActFor((int) $user->getKey(), $studentId);
    }

    /** Pengemudi hanya trip yang ditugaskan kepadanya (§87 Rule 15). */
    public function canOperateTrip(mixed $user, Trip $trip): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->can('transportation.trip.dispatch') || $user->can('transportation.trip.manage')) {
            return true;
        }

        $profile = $this->driverProfile($user);

        return $profile !== null && (int) $trip->driver_profile_id === (int) $profile->id;
    }

    /** Batasi query trip sesuai cakupan pengguna. */
    public function scopeTrips(Builder $query, mixed $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $profile = $this->driverProfile($user);
        $driverOnly = ! $user->can('transportation.trip.view') && $user->can('transportation.trip.operate');

        if ($driverOnly && $profile) {
            return $query->where('driver_profile_id', $profile->id);
        }

        return $query;
    }

    /** Trip yang boleh dilihat pengguna (untuk portal siswa/wali). */
    public function studentTrips(mixed $user, int $studentId, ?string $from = null, ?string $to = null): array
    {
        if (! $this->canAccessStudent($user, $studentId)) {
            return [];
        }

        return Trip::query()
            ->whereHas('passengers', fn ($q) => $q->where('student_id', $studentId))
            ->when($from, fn ($q, $value) => $q->whereDate('trip_date', '>=', $value))
            ->when($to, fn ($q, $value) => $q->whereDate('trip_date', '<=', $value))
            ->orderByDesc('trip_date')
            ->limit(50)
            ->get()
            ->map(fn (Trip $trip): array => [
                'id' => $trip->id,
                'trip_number' => $trip->trip_number,
                'trip_date' => $trip->trip_date->toDateString(),
                'direction' => $trip->direction,
                'status' => $trip->status,
                'planned_departure' => $trip->planned_departure,
                'actual_start_at' => $trip->actual_start_at,
                'actual_end_at' => $trip->actual_end_at,
                'delay_minutes' => $trip->delay_minutes,
            ])
            ->all();
    }

    public function assignmentsFor(mixed $user, int $studentId): array
    {
        if (! $this->canAccessStudent($user, $studentId)) {
            return [];
        }

        return TransportationAssignment::query()
            ->where('student_id', $studentId)
            ->where('status', 'ACTIVE')
            ->with(['route', 'pickupStop', 'dropoffStop'])
            ->get()
            ->map(fn (TransportationAssignment $assignment): array => [
                'id' => $assignment->id,
                'assignment_code' => $assignment->assignment_code,
                'route' => $assignment->route?->only(['id', 'code', 'name']),
                'pickup_stop' => $assignment->pickupStop?->only(['id', 'name', 'planned_arrival']),
                'dropoff_stop' => $assignment->dropoffStop?->only(['id', 'name', 'planned_arrival']),
                'effective_from' => $assignment->effective_from?->toDateString(),
                'effective_until' => $assignment->effective_until?->toDateString(),
            ])
            ->all();
    }

    protected function isParentOnly(mixed $user): bool
    {
        if (! method_exists($user, 'getRoleNames')) {
            return false;
        }

        $roles = array_map('strtoupper', $user->getRoleNames()->all());

        return $roles !== [] && count(array_diff($roles, ['PARENT', 'STUDENT'])) === count($roles);
    }
}
