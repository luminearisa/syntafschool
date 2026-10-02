<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\{BoardingGatewayInterface, StudentGatewayInterface};
use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\{
    AuthorizedPickupPerson, ParentTransportAuthorization, StudentTransportProfile, TransportationAssignment,
    TransportationRoute, Trip, TripManifest
};
use Illuminate\Validation\ValidationException;

/**
 * Penugasan siswa ke transportasi (§22-§25).
 * Penugasan adalah sumber manifes harian; perubahan langsung menyegarkan
 * manifes trip yang belum berjalan.
 */
class TransportationAssignmentService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly StudentGatewayInterface $students,
        private readonly RouteService $routes,
        private readonly ManifestService $manifest,
        private readonly BoardingGatewayInterface $boarding,
        private readonly TransportationNotificationService $notifications,
    ) {
    }

    /** Profil transportasi siswa (§22). */
    public function upsertProfile(int $studentId, array $payload): StudentTransportProfile
    {
        $student = $this->students->snapshot($studentId);
        if (! $student) {
            throw ValidationException::withMessages(['student_id' => 'Siswa tidak ditemukan pada data akademik.']);
        }

        $profile = StudentTransportProfile::query()->firstOrNew(['student_id' => $studentId]);
        $profile->fill(array_intersect_key($payload, array_flip([
            'campus_id', 'transport_required', 'transport_type', 'service_id', 'pickup_stop_id', 'dropoff_stop_id',
            'morning_route_id', 'afternoon_route_id', 'effective_from', 'effective_until', 'special_requirement',
            'special_requirement_note', 'escort_required', 'wheelchair_required', 'authorized_pickup_restricted',
            'status', 'notes',
        ])))->save();

        if (! $profile->wasRecentlyCreated) {
            // perubahan profil ikut menyegarkan penugasan aktif
            TransportationAssignment::query()->where('student_id', $studentId)->where('status', 'ACTIVE')
                ->update([
                    'pickup_stop_id' => $profile->pickup_stop_id,
                    'dropoff_stop_id' => $profile->dropoff_stop_id,
                    'morning_route_id' => $profile->morning_route_id,
                    'afternoon_route_id' => $profile->afternoon_route_id,
                    'updated_at' => now(),
                ]);
        }

        $this->context->audit('StudentTransportProfileUpserted', $profile, ['student_id' => $studentId]);

        return $profile->refresh();
    }

    /**
     * Buat penugasan (§25/§27).
     * Memeriksa: siswa aktif, rute aktif, halte milik rute (§87 Rule 7),
     * tidak ada penugasan ganda pada periode sama (§87 Rule 5), dan kapasitas rute.
     */
    public function create(array $payload): array
    {
        $studentId = (int) $payload['student_id'];
        $student = $this->students->snapshot($studentId);
        if (! $student) {
            throw ValidationException::withMessages(['student_id' => 'Siswa tidak ditemukan.']);
        }
        if (! ($student['active'] ?? true) && ! ($payload['allow_override'] ?? false)) {
            throw ValidationException::withMessages(['student_id' => 'Siswa tidak berstatus aktif.']);
        }

        $morningRouteId = $payload['morning_route_id'] ?? $payload['route_id'] ?? null;
        $afternoonRouteId = $payload['afternoon_route_id'] ?? $payload['route_id'] ?? null;
        $routeId = $payload['route_id'] ?? $morningRouteId;

        $route = $routeId ? TransportationRoute::query()->find($routeId) : null;
        if (! $route || ! $route->active) {
            throw ValidationException::withMessages(['route_id' => 'Rute tidak ditemukan atau tidak aktif.']);
        }

        foreach ([['pickup_stop_id', $payload['pickup_stop_id'] ?? null, $route->id],
                  ['dropoff_stop_id', $payload['dropoff_stop_id'] ?? null, $route->id]] as [$field, $stopId, $routeRef]) {
            if ($stopId && ! $this->routes->stopBelongs((int) $routeRef, (int) $stopId) && ! ($payload['allow_override'] ?? false)) {
                throw ValidationException::withMessages([$field => 'Halte tidak berada pada rute yang dipilih (§87 Rule 7).']);
            }
        }

        $effectiveFrom = $payload['effective_from'] ?? now()->toDateString();
        $effectiveUntil = $payload['effective_until'] ?? null;

        $overlap = TransportationAssignment::query()
            ->where('student_id', $studentId)
            ->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $effectiveFrom))
            ->when($effectiveUntil, fn ($q) => $q->where(fn ($inner) => $inner->whereNull('effective_from')->orWhereDate('effective_from', '<=', $effectiveUntil)))
            ->first();

        if ($overlap && ! ($payload['allow_multi_service'] ?? false)) {
            return [
                'created' => false,
                'reason' => 'Siswa sudah memiliki penugasan aktif pada periode yang sama (§87 Rule 5).',
                'existing_assignment_id' => $overlap->id,
            ];
        }

        $capacity = $this->routes->routeCapacity($route->id, $effectiveFrom);
        if ($capacity['is_full'] && ! ($payload['allow_override'] ?? false)) {
            return [
                'created' => false,
                'reason' => 'Kapasitas rute penuh ('.$capacity['assigned'].'/'.$capacity['capacity'].'). Ajukan daftar tunggu.',
                'capacity' => $capacity,
            ];
        }

        $assignment = TransportationAssignment::query()->create([
            'assignment_code' => $payload['assignment_code'] ?? ('AST-'.now()->format('ymd').'-'.str_pad((string) (TransportationAssignment::query()->count() + 1), 4, '0', STR_PAD_LEFT)),
            'student_id' => $studentId,
            'request_id' => $payload['request_id'] ?? null,
            'service_id' => $payload['service_id'] ?? $route->service_id,
            'campus_id' => $payload['campus_id'] ?? $route->campus_id,
            'route_id' => $route->id,
            'morning_route_id' => $morningRouteId,
            'afternoon_route_id' => $afternoonRouteId,
            'pickup_stop_id' => $payload['pickup_stop_id'] ?? null,
            'dropoff_stop_id' => $payload['dropoff_stop_id'] ?? null,
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
            'status' => 'ACTIVE',
            'transport_mode' => strtoupper((string) ($payload['transport_mode'] ?? 'RECURRING')),
            'billable' => (bool) ($payload['billable'] ?? false),
            'fee_amount' => $payload['fee_amount'] ?? null,
            'assigned_by' => $payload['assigned_by'] ?? $this->context->actorId(),
            'approved_by' => $payload['approved_by'] ?? null,
            'approved_at' => isset($payload['approved_by']) ? now() : null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $profile = StudentTransportProfile::query()->firstOrNew(['student_id' => $studentId]);
        $profile->fill([
            'transport_required' => true,
            'service_id' => $assignment->service_id,
            'campus_id' => $assignment->campus_id,
            'pickup_stop_id' => $assignment->pickup_stop_id,
            'dropoff_stop_id' => $assignment->dropoff_stop_id,
            'morning_route_id' => $assignment->morning_route_id,
            'afternoon_route_id' => $assignment->afternoon_route_id,
            'effective_from' => $assignment->effective_from,
            'effective_until' => $assignment->effective_until,
            'status' => 'ACTIVE',
        ])->save();

        $this->refreshUpcomingManifests($studentId);
        $this->context->publish('TransportationAssignmentCreated', $assignment, [
            'student_id' => $studentId,
            'route_id' => $route->id,
            'pickup_stop_id' => $assignment->pickup_stop_id,
            'dropoff_stop_id' => $assignment->dropoff_stop_id,
        ]);

        $this->notifications->toGuardians('TransportationAssignmentCreated', $studentId,
            'Pendaftaran transportasi disetujui',
            'Putra/putri Anda terdaftar pada rute '.$route->name.' mulai '.$effectiveFrom.'.',
            ['idempotency_key' => 'assign-'.$assignment->id]
        );

        return [
            'created' => true,
            'assignment_id' => $assignment->id,
            'assignment_code' => $assignment->assignment_code,
            'capacity' => $this->routes->routeCapacity($route->id, $effectiveFrom),
        ];
    }

    public function suspend(TransportationAssignment $assignment, string $reason, ?string $until = null): TransportationAssignment
    {
        $assignment->forceFill([
            'status' => 'SUSPENDED',
            'suspended_at' => now(),
            'suspend_reason' => $reason,
            'effective_until' => $until ?? $assignment->effective_until,
        ])->save();

        $this->refreshUpcomingManifests((int) $assignment->student_id);
        $this->context->publish('TransportationAssignmentChanged', $assignment, ['change' => 'SUSPENDED', 'reason' => $reason]);

        return $assignment->refresh();
    }

    public function resume(TransportationAssignment $assignment, ?string $from = null): TransportationAssignment
    {
        $assignment->forceFill([
            'status' => 'ACTIVE',
            'suspended_at' => null,
            'suspend_reason' => null,
            'effective_from' => $from ?? now()->toDateString(),
        ])->save();

        $this->refreshUpcomingManifests((int) $assignment->student_id);
        $this->context->publish('TransportationAssignmentChanged', $assignment, ['change' => 'RESUMED']);

        return $assignment->refresh();
    }

    public function changeRoute(TransportationAssignment $assignment, int $routeId, ?int $pickupStopId, ?int $dropoffStopId, array $options = []): TransportTransportAssignmentResult|TransportationAssignment
    {
        $route = TransportationRoute::query()->findOrFail($routeId);
        foreach ([['pickup_stop_id', $pickupStopId], ['dropoff_stop_id', $dropoffStopId]] as [$field, $stopId]) {
            if ($stopId && ! $this->routes->stopBelongs($routeId, (int) $stopId) && ! ($options['allow_override'] ?? false)) {
                throw ValidationException::withMessages([$field => 'Halte tidak berada pada rute tujuan.']);
            }
        }

        $capacity = $this->routes->routeCapacity($routeId);
        if ($capacity['is_full'] && ! ($options['allow_override'] ?? false)) {
            return new TransportTransportAssignmentResult(false, 'Kapasitas rute tujuan penuh.', $capacity);
        }

        $assignment->forceFill([
            'route_id' => $routeId,
            'morning_route_id' => $options['morning_route_id'] ?? $routeId,
            'afternoon_route_id' => $options['afternoon_route_id'] ?? $routeId,
            'pickup_stop_id' => $pickupStopId,
            'dropoff_stop_id' => $dropoffStopId,
        ])->save();

        $this->refreshUpcomingManifests((int) $assignment->student_id);
        $this->context->publish('TransportationAssignmentChanged', $assignment, [
            'change' => 'ROUTE_CHANGED', 'route_id' => $routeId,
        ]);

        return $assignment->refresh();
    }

    public function end(TransportationAssignment $assignment, string $reason, ?string $endDate = null): TransportationAssignment
    {
        $assignment->forceFill([
            'status' => 'ENDED',
            'effective_until' => $endDate ?? now()->toDateString(),
            'suspend_reason' => $reason,
        ])->save();

        $this->refreshUpcomingManifests((int) $assignment->student_id);
        $this->context->publish('TransportationAssignmentChanged', $assignment, ['change' => 'ENDED', 'reason' => $reason]);

        return $assignment->refresh();
    }

    /** Segarkan manifes trip belum-berjalan agar perubahan penugasan terlihat (§33). */
    public function refreshUpcomingManifests(int $studentId): int
    {
        $assignments = TransportationAssignment::query()->where('student_id', $studentId)->where('status', 'ACTIVE')->get();
        $refreshed = 0;

        foreach ($assignments as $assignment) {
            $trips = Trip::query()
                ->whereIn('route_id', array_filter([$assignment->route_id, $assignment->morning_route_id, $assignment->afternoon_route_id]))
                ->whereIn('status', ['PLANNED', 'VEHICLE_ASSIGNED', 'DRIVER_ASSIGNED'])
                ->get();
            foreach ($trips as $trip) {
                $this->manifest->generate($trip, ['rebuild' => true]);
                $refreshed++;
            }
        }

        return $refreshed;
    }

    public function assignments(array $filters = []): array
    {
        return TransportationAssignment::query()
            ->when($filters['student_id'] ?? null, fn ($q, $studentId) => $q->where('student_id', $studentId))
            ->when($filters['route_id'] ?? null, fn ($q, $routeId) => $q->where('route_id', $routeId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->when($filters['campus_id'] ?? null, fn ($q, $campus) => $q->where('campus_id', $campus))
            ->with(['route:id,code,name', 'pickupStop:id,name', 'dropoffStop:id,name'])
            ->orderByDesc('id')
            ->limit((int) ($filters['limit'] ?? 200))
            ->get()
            ->map(fn (TransportationAssignment $assignment): array => [
                'id' => $assignment->id,
                'assignment_code' => $assignment->assignment_code,
                'student_id' => $assignment->student_id,
                'route' => $assignment->route?->only(['id', 'code', 'name']),
                'pickup_stop' => $assignment->pickupStop?->only(['id', 'name']),
                'dropoff_stop' => $assignment->dropoffStop?->only(['id', 'name']),
                'status' => $assignment->status,
                'transport_mode' => $assignment->transport_mode,
                'effective_from' => $assignment->effective_from?->toDateString(),
                'effective_until' => $assignment->effective_until?->toDateString(),
                'billable' => (bool) $assignment->billable,
            ])
            ->all();
    }

    /** Penjemput berwenang (§24). */
    public function addAuthorizedPickupPerson(int $studentId, array $payload): AuthorizedPickupPerson
    {
        $person = AuthorizedPickupPerson::query()->create([
            'student_id' => $studentId,
            'name' => $payload['name'],
            'relationship' => $payload['relationship'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'identity_type' => $payload['identity_type'] ?? null,
            'identity_number' => $payload['identity_number'] ?? null,
            'identity_hash' => ! empty($payload['identity_number']) ? hash('sha256', (string) $payload['identity_number']) : null,
            'photo_path' => $payload['photo_path'] ?? null,
            'valid_from' => $payload['valid_from'] ?? now()->toDateString(),
            'valid_until' => $payload['valid_until'] ?? null,
            'status' => 'ACTIVE',
            'created_by' => $this->context->actorId(),
        ]);

        $this->context->audit('AuthorizedPickupPersonAdded', $person, ['student_id' => $studentId]);

        return $person;
    }

    public function revokeAuthorizedPickupPerson(AuthorizedPickupPerson $person, string $reason): AuthorizedPickupPerson
    {
        $person->forceFill(['status' => 'REVOKED'])->save();
        $this->context->audit('AuthorizedPickupPersonRevoked', $person, ['reason' => $reason]);

        return $person->refresh();
    }

    /** Otorisasi wali (§23). */
    public function createParentAuthorization(int $studentId, array $payload): ParentTransportAuthorization
    {
        $authorization = ParentTransportAuthorization::query()->create([
            'student_id' => $studentId,
            'guardian_id' => $payload['guardian_id'] ?? null,
            'authorization_type' => strtoupper((string) ($payload['authorization_type'] ?? 'RECURRING')),
            'pickup_stop_id' => $payload['pickup_stop_id'] ?? null,
            'dropoff_stop_id' => $payload['dropoff_stop_id'] ?? null,
            'transportation_permission' => (bool) ($payload['transportation_permission'] ?? true),
            'authorized_person_name' => $payload['authorized_person_name'] ?? null,
            'authorized_person_phone' => $payload['authorized_person_phone'] ?? null,
            'special_instruction' => $payload['special_instruction'] ?? null,
            'valid_from' => $payload['valid_from'] ?? now()->toDateString(),
            'valid_until' => $payload['valid_until'] ?? null,
            'status' => 'ACTIVE',
        ]);

        $this->context->audit('ParentTransportAuthorizationCreated', $authorization, ['student_id' => $studentId]);

        return $authorization;
    }

    public function profile(int $studentId): ?array
    {
        $profile = StudentTransportProfile::query()->where('student_id', $studentId)->first();
        if (! $profile) {
            return null;
        }

        return $profile->toArray() + [
            'authorized_pickup_persons' => AuthorizedPickupPerson::query()
                ->where('student_id', $studentId)->where('status', 'ACTIVE')
                ->get(['id', 'name', 'relationship', 'valid_from', 'valid_until'])->all(),
            'authorizations' => ParentTransportAuthorization::query()
                ->where('student_id', $studentId)->where('status', 'ACTIVE')
                ->get(['id', 'authorization_type', 'authorized_person_name', 'valid_from', 'valid_until'])->all(),
            'is_boarding_resident' => $this->boarding->isResident($studentId),
        ];
    }
}

/** Hasil penugasan yang ditolak tanpa melempar exception. */
class TransportTransportAssignmentResult
{
    public function __construct(
        public readonly bool $created,
        public readonly string $reason,
        public readonly array $capacity = [],
    ) {
    }
}
