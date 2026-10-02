<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\{BoardingGatewayInterface, EmployeeGatewayInterface, ParentGatewayInterface, StudentGatewayInterface, WorkflowGatewayInterface};
use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\{
    StudentTransportProfile, TransportationAssignment, TransportationRequest, TransportationRoute, TransportationWaitlist
};
use Illuminate\Validation\ValidationException;

/**
 * Permintaan transportasi (§26) & daftar tunggu (§28).
 *
 * Alur: Request → Validation → Capacity Check → Route Check → Approval →
 * Assignment (Workflow Engine untuk persetujuan bila tersedia).
 */
class TransportationRequestService
{
    public const TYPES = ['NEW', 'CHANGE_ROUTE', 'CHANGE_STOP', 'SUSPEND', 'RESUME', 'TEMPORARY', 'SPECIAL'];

    public function __construct(
        private readonly TransportContext $context,
        private readonly StudentGatewayInterface $students,
        private readonly ParentGatewayInterface $parents,
        private readonly EmployeeGatewayInterface $employees,
        private readonly RouteService $routes,
        private readonly TransportationAssignmentService $assignments,
        private readonly BoardingGatewayInterface $boarding,
        private readonly WorkflowGatewayInterface $workflow,
        private readonly TransportationNotificationService $notifications,
    ) {
    }

    /** Ajukan permintaan transportasi. */
    public function submit(array $payload, mixed $actor = null): array
    {
        $type = strtoupper((string) ($payload['request_type'] ?? 'NEW'));
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['request_type' => 'Jenis permintaan tidak dikenal: '.$type]);
        }

        $studentId = isset($payload['student_id']) ? (int) $payload['student_id'] : null;
        $actorId = $actor?->getKey() ? (int) $actor->getKey() : $this->context->actorId();

        // Wali hanya boleh mengajukan untuk anaknya sendiri (§87 Rule 14).
        if ($studentId && $actor && ! ($payload['requested_by_type'] ?? null) === 'STAFF') {
            if (method_exists($actor, 'getRoleNames') && $actor->getRoleNames()->isNotEmpty()) {
                $isParentOnly = $actor->getRoleNames()->every(fn ($role) => in_array(strtoupper((string) $role), ['PARENT', 'STUDENT'], true));
                if ($isParentOnly && ! $this->parents->canActFor($actorId, $studentId)) {
                    throw ValidationException::withMessages([
                        'student_id' => 'Anda hanya dapat mengajukan transportasi untuk anak Anda sendiri.',
                    ]);
                }
            }
        }

        $student = $studentId ? $this->students->snapshot($studentId) : null;
        if ($studentId && ! $student) {
            throw ValidationException::withMessages(['student_id' => 'Data siswa tidak ditemukan.']);
        }

        $routeId = $payload['route_id'] ?? $payload['morning_route_id'] ?? null;
        $route = $routeId ? TransportationRoute::query()->find($routeId) : null;
        if ($type === 'NEW' && (! $route || ! $route->active)) {
            throw ValidationException::withMessages(['route_id' => 'Rute belum dipilih atau tidak aktif.']);
        }

        foreach ([['pickup_stop_id', $payload['pickup_stop_id'] ?? null], ['dropoff_stop_id', $payload['dropoff_stop_id'] ?? null]] as [$field, $stopId]) {
            if ($stopId && $route && ! $this->routes->stopBelongs($route->id, (int) $stopId)) {
                throw ValidationException::withMessages([$field => 'Halte tidak berada pada rute yang dipilih (§87 Rule 7).']);
            }
        }

        $capacity = $route ? $this->routes->routeCapacity($route->id, $payload['effective_from'] ?? now()->toDateString()) : null;
        $validation = [
            'student_active' => (bool) ($student['active'] ?? false),
            'route_active' => (bool) ($route?->active),
            'stop_valid' => true,
            'boarding_resident' => $studentId ? $this->boarding->isResident($studentId) : false,
            'special_requirement' => $payload['special_requirement'] ?? null,
        ];

        $request = TransportationRequest::query()->create([
            'request_number' => 'TREQ-'.now()->format('ymd').'-'.str_pad((string) (TransportationRequest::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'request_type' => $type,
            'student_id' => $studentId,
            'guardian_id' => $payload['guardian_id'] ?? null,
            'requested_by' => $actorId,
            'requested_by_type' => strtoupper((string) ($payload['requested_by_type'] ?? 'PARENT')),
            'service_id' => $payload['service_id'] ?? $route?->service_id,
            'campus_id' => $payload['campus_id'] ?? $route?->campus_id,
            'route_id' => $route?->id,
            'morning_route_id' => $payload['morning_route_id'] ?? $route?->id,
            'afternoon_route_id' => $payload['afternoon_route_id'] ?? null,
            'pickup_stop_id' => $payload['pickup_stop_id'] ?? null,
            'dropoff_stop_id' => $payload['dropoff_stop_id'] ?? null,
            'effective_from' => $payload['effective_from'] ?? now()->toDateString(),
            'effective_until' => $payload['effective_until'] ?? null,
            'priority_level' => strtoupper((string) ($payload['priority_level'] ?? 'NORMAL')),
            'reason' => $payload['reason'] ?? null,
            'special_requirement' => $payload['special_requirement'] ?? null,
            'validation' => $validation,
            'capacity_check' => $capacity,
            'status' => 'SUBMITTED',
        ]);

        $this->context->publish('TransportationRequestCreated', $request, [
            'request_number' => $request->request_number,
            'type' => $type,
            'student_id' => $studentId,
        ]);

        // Kapasitas penuh → daftar tunggu (§28)
        if ($type === 'NEW' && $capacity && $capacity['is_full']) {
            $waitlist = $this->enqueue($request, $payload);

            return [
                'request_id' => $request->id,
                'request_number' => $request->request_number,
                'status' => 'ON_WAITLIST',
                'waitlist_entry_id' => $waitlist->id,
                'priority_score' => $waitlist->priority_score,
                'capacity' => $capacity,
                'message' => 'Rute penuh; permintaan masuk daftar tunggu dan akan ditawarkan saat kursi tersedia.',
            ];
        }

        $workflow = $this->workflow->start('TRANSPORTATION_REQUEST', [
            'request_id' => $request->id,
            'request_number' => $request->request_number,
            'type' => $type,
            'student_id' => $studentId,
            'route_id' => $request->route_id,
        ]);
        $request->forceFill([
            'status' => 'VALIDATED',
            'workflow_reference' => $workflow['reference'] ?? null,
        ])->save();

        return [
            'request_id' => $request->id,
            'request_number' => $request->request_number,
            'status' => 'VALIDATED',
            'validation' => $validation,
            'capacity' => $capacity,
            'workflow' => $workflow,
        ];
    }

    /** Setujui permintaan dan terapkan efeknya (§26). */
    public function approve(int $requestId, ?int $actorId = null, array $options = []): array
    {
        $request = TransportationRequest::query()->findOrFail($requestId);
        if (in_array($request->status, ['ASSIGNED', 'REJECTED', 'CANCELLED'], true)) {
            return ['approved' => false, 'reason' => 'Permintaan sudah berstatus '.$request->status.'.'];
        }

        $actorId ??= $this->context->actorId();
        $type = $request->request_type;

        $applied = match ($type) {
            'NEW', 'TEMPORARY', 'SPECIAL' => $this->assignments->create([
                'student_id' => (int) $request->student_id,
                'request_id' => $request->id,
                'service_id' => $request->service_id,
                'campus_id' => $request->campus_id,
                'route_id' => $request->route_id,
                'morning_route_id' => $request->morning_route_id,
                'afternoon_route_id' => $request->afternoon_route_id,
                'pickup_stop_id' => $request->pickup_stop_id,
                'dropoff_stop_id' => $request->dropoff_stop_id,
                'effective_from' => $request->effective_from?->toDateString(),
                'effective_until' => $request->effective_until?->toDateString(),
                'transport_mode' => $type === 'NEW' ? 'RECURRING' : $type,
                'assigned_by' => $actorId,
                'approved_by' => $actorId,
                'allow_override' => $options['allow_override'] ?? false,
            ]),
            'CHANGE_ROUTE', 'CHANGE_STOP' => $this->applyChange($request, $options),
            'SUSPEND' => $this->applyStatusChange($request, 'SUSPENDED', $options),
            'RESUME' => $this->applyStatusChange($request, 'ACTIVE', $options),
            default => ['created' => false, 'reason' => 'Jenis permintaan belum didukung untuk persetujuan otomatis.'],
        };

        if (($applied['created'] ?? false) === false && isset($applied['reason'])) {
            return ['approved' => false, 'reason' => $applied['reason'], 'detail' => $applied];
        }

        $this->workflow->approve((string) $request->workflow_reference, ['approved_by' => $actorId, 'request_id' => $request->id]);

        $request->forceFill([
            'status' => 'ASSIGNED',
            'approved_by' => $actorId,
            'approved_at' => now(),
            'created_assignment_id' => $applied['assignment_id'] ?? null,
        ])->save();

        if ($request->student_id) {
            $this->notifications->toGuardians('TransportationAssignmentCreated', (int) $request->student_id,
                'Permintaan transportasi disetujui',
                'Permintaan '.$request->request_number.' disetujui. '.($applied['message'] ?? ''),
                ['idempotency_key' => 'req-approve-'.$request->id]
            );
        }

        $this->context->audit('TransportationRequestApproved', $request, ['type' => $type, 'applied' => $applied]);

        return ['approved' => true, 'request_id' => $request->id, 'status' => $request->status, 'applied' => $applied];
    }

    public function reject(int $requestId, string $reason, ?int $actorId = null): array
    {
        $request = TransportationRequest::query()->findOrFail($requestId);
        $request->forceFill([
            'status' => 'REJECTED',
            'rejection_reason' => $reason,
            'approved_by' => $actorId ?? $this->context->actorId(),
            'approved_at' => now(),
        ])->save();

        TransportationWaitlist::query()->where('request_id', $request->id)->where('status', 'WAITING')
            ->update(['status' => 'CANCELLED', 'updated_at' => now()]);

        $this->context->audit('TransportationRequestRejected', $request, ['reason' => $reason]);

        if ($request->student_id) {
            $this->notifications->toGuardians('TransportationRequestRejected', (int) $request->student_id,
                'Permintaan transportasi ditolak',
                'Permintaan '.$request->request_number.' ditolak. Alasan: '.$reason,
                ['idempotency_key' => 'req-reject-'.$request->id]
            );
        }

        return ['rejected' => true, 'request_id' => $request->id, 'status' => 'REJECTED'];
    }

    private function applyChange(TransportationRequest $request, array $options): array
    {
        $assignment = TransportationAssignment::query()
            ->where('student_id', $request->student_id)->where('status', 'ACTIVE')->latest('id')->first();

        if (! $assignment) {
            return ['created' => false, 'reason' => 'Tidak ada penugasan aktif untuk diubah.'];
        }

        $updated = $this->assignments->changeRoute(
            $assignment,
            (int) ($request->route_id ?? $assignment->route_id),
            $request->pickup_stop_id,
            $request->dropoff_stop_id,
            $options
        );

        if ($updated instanceof \App\Modules\SchoolOperations\Transportation\Application\Services\TransportTransportAssignmentResult) {
            return ['created' => false, 'reason' => $updated->reason, 'capacity' => $updated->capacity];
        }

        return ['created' => true, 'assignment_id' => $assignment->id, 'message' => 'Rute/halte penugasan diperbarui.'];
    }

    private function applyStatusChange(TransportationRequest $request, string $status, array $options): array
    {
        $assignment = TransportationAssignment::query()
            ->where('student_id', $request->student_id)->where('status', 'ACTIVE')->latest('id')->first()
            ?? TransportationAssignment::query()->where('student_id', $request->student_id)->latest('id')->first();

        if (! $assignment) {
            return ['created' => false, 'reason' => 'Tidak ada penugasan untuk diubah statusnya.'];
        }

        if ($status === 'SUSPENDED') {
            $this->assignments->suspend($assignment, $request->reason ?? 'Permintaan penangguhan.', $request->effective_until?->toDateString());

            return ['created' => true, 'assignment_id' => $assignment->id, 'message' => 'Layanan transportasi ditangguhkan.'];
        }

        $this->assignments->resume($assignment, $request->effective_from?->toDateString());

        return ['created' => true, 'assignment_id' => $assignment->id, 'message' => 'Layanan transportasi diaktifkan kembali.'];
    }

    // ── Daftar tunggu (§28) ────────────────────────────────────────────────

    public function enqueue(TransportationRequest $request, array $options = []): TransportationWaitlist
    {
        $student = $request->student_id ? $this->students->snapshot((int) $request->student_id) : null;
        $profile = $request->student_id
            ? StudentTransportProfile::query()->where('student_id', $request->student_id)->first()
            : null;

        $isResident = $request->student_id ? $this->boarding->isResident((int) $request->student_id) : false;
        $special = (bool) ($profile?->special_requirement || $profile?->wheelchair_required || $profile?->escort_required);

        $score = $this->priorityScore([
            'special_need' => $special,
            'boarding_status' => $isResident,
            'sibling' => $this->hasSiblingAssigned((int) $request->student_id),
            'grade_level' => $student['grade_level'] ?? null,
            'requested_at' => $request->created_at,
            'manual_priority' => (bool) ($options['manual_priority'] ?? false),
        ]);

        $entry = TransportationWaitlist::query()->create([
            'request_id' => $request->id,
            'student_id' => (int) $request->student_id,
            'route_id' => $request->route_id,
            'service_id' => $request->service_id,
            'priority_score' => $score['score'],
            'priority_class' => $score['class'],
            'grade_level' => $student['grade_level'] ?? null,
            'special_need' => $special,
            'boarding_status' => $isResident,
            'manual_priority' => (bool) ($options['manual_priority'] ?? false),
            'requested_at' => $request->created_at ?? now(),
            'status' => 'WAITING',
            'history' => [['event' => 'ENQUEUED', 'at' => now()->toIso8601String(), 'score' => $score]],
        ]);

        $request->forceFill(['status' => 'ON_WAITLIST'])->save();

        $this->context->publish('TransportationWaitlistEnqueued', $entry, [
            'student_id' => $entry->student_id, 'route_id' => $entry->route_id, 'score' => $entry->priority_score,
        ]);

        return $entry;
    }

    /** Skor prioritas daftar tunggu (§28). */
    public function priorityScore(array $context): array
    {
        $weights = (array) config('transportation.waitlist.priority_weights', []);
        $score = 0;
        $components = [];

        if ($context['special_need'] ?? false) {
            $score += (int) ($weights['special_need'] ?? 40);
            $components[] = 'SPECIAL_NEED';
        }
        if ($context['boarding_status'] ?? false) {
            $score += (int) ($weights['boarding_status'] ?? 20);
            $components[] = 'BOARDING';
        }
        if ($context['sibling'] ?? false) {
            $score += (int) ($weights['sibling'] ?? 15);
            $components[] = 'SIBLING';
        }
        if (! empty($context['grade_level'])) {
            $seniority = min(10, max(0, (int) preg_replace('/\D/', '', (string) $context['grade_level']) - 1));
            $score += (int) round(((int) ($weights['grade_seniority'] ?? 10)) * ($seniority / 10));
            $components[] = 'GRADE_'.$context['grade_level'];
        }
        if (! empty($context['requested_at'])) {
            $days = now()->diffInDays(\Illuminate\Support\Carbon::parse($context['requested_at']));
            $waiting = min((int) ($weights['waiting_time'] ?? 15), (int) $days);
            $score += $waiting;
            $components[] = 'WAITING_'.$days.'D';
        }
        if ($context['manual_priority'] ?? false) {
            $score += (int) ($weights['manual_priority'] ?? 50);
            $components[] = 'MANUAL';
        }

        return [
            'score' => $score,
            'class' => $score >= 60 ? 'URGENT' : ($score >= 35 ? 'HIGH' : ($score >= 15 ? 'NORMAL' : 'LOW')),
            'components' => $components,
        ];
    }

    private function hasSiblingAssigned(int $studentId): bool
    {
        $guardians = $this->parents->guardiansOf($studentId);
        if ($guardians === []) {
            return false;
        }

        $guardianIds = array_column($guardians, 'guardian_id');
        $siblingIds = \Illuminate\Support\Facades\DB::table('student_guardians')
            ->whereIn('guardian_id', $guardianIds)
            ->where('student_id', '!=', $studentId)
            ->pluck('student_id')->all();

        if ($siblingIds === []) {
            return false;
        }

        return TransportationAssignment::query()->whereIn('student_id', $siblingIds)->where('status', 'ACTIVE')->exists();
    }

    /** Evaluasi daftar tunggu → tawarkan kursi (§28). */
    public function evaluateWaitlist(?int $routeId = null, ?int $seats = null): array
    {
        $routeIds = $routeId ? [$routeId] : TransportationWaitlist::query()->where('status', 'WAITING')->pluck('route_id')->unique()->filter()->all();
        $offers = [];

        foreach ($routeIds as $id) {
            $capacity = $this->routes->routeCapacity((int) $id);
            $available = $seats ?? $capacity['available_seats'];
            if ($available <= 0) {
                continue;
            }

            $candidates = TransportationWaitlist::query()
                ->where('route_id', $id)->where('status', 'WAITING')
                ->orderByDesc('priority_score')->orderBy('requested_at')
                ->limit($available)
                ->get();

            foreach ($candidates as $candidate) {
                $expires = now()->addHours((int) config('transportation.waitlist.offer_ttl_hours', 48));
                $candidate->forceFill([
                    'status' => 'OFFERED',
                    'offer_expires_at' => $expires,
                    'history' => array_merge((array) $candidate->history, [[
                        'event' => 'OFFERED', 'at' => now()->toIso8601String(), 'expires_at' => $expires->toIso8601String(),
                    ]]),
                ])->save();

                $this->notifications->toGuardians('TransportationSeatOffered', (int) $candidate->student_id,
                    'Kursi transportasi tersedia',
                    'Kursi pada rute tersedia untuk putra/putri Anda. Konfirmasi sebelum '.$expires->format('d M Y H:i').'.',
                    ['idempotency_key' => 'offer-'.$candidate->id]
                );

                $this->context->publish('TransportationWaitlistOffered', $candidate, [
                    'student_id' => $candidate->student_id, 'route_id' => $id, 'expires_at' => $expires->toIso8601String(),
                ]);

                $offers[] = ['waitlist_id' => $candidate->id, 'student_id' => $candidate->student_id, 'priority_score' => $candidate->priority_score, 'expires_at' => $expires];
            }
        }

        return ['route_ids' => $routeIds, 'offers' => $offers, 'offer_count' => count($offers)];
    }

    /** Wali menerima tawaran kursi → penugasan dibuat. */
    public function acceptOffer(int $waitlistId, ?int $actorId = null): array
    {
        $entry = TransportationWaitlist::query()->findOrFail($waitlistId);
        if (! in_array($entry->status, ['OFFERED', 'WAITING'], true)) {
            return ['accepted' => false, 'reason' => 'Tawaran sudah berstatus '.$entry->status.'.'];
        }
        if ($entry->offer_expires_at && $entry->offer_expires_at->isPast()) {
            $entry->forceFill(['status' => 'EXPIRED'])->save();

            return ['accepted' => false, 'reason' => 'Tawaran kursi sudah kedaluwarsa.'];
        }

        $request = TransportationRequest::query()->findOrFail($entry->request_id);
        $result = $this->assignments->create([
            'student_id' => (int) $entry->student_id,
            'request_id' => $request->id,
            'service_id' => $entry->service_id,
            'route_id' => $entry->route_id,
            'pickup_stop_id' => $request->pickup_stop_id,
            'dropoff_stop_id' => $request->dropoff_stop_id,
            'effective_from' => $request->effective_from?->toDateString() ?? now()->toDateString(),
            'assigned_by' => $actorId ?? $this->context->actorId(),
            'approved_by' => $actorId ?? $this->context->actorId(),
        ]);

        if (($result['created'] ?? false) === false) {
            return ['accepted' => false, 'reason' => $result['reason'] ?? 'Penugasan gagal dibuat.'];
        }

        $entry->forceFill([
            'status' => 'ACCEPTED',
            'promoted_at' => now(),
            'promoted_by' => $actorId ?? $this->context->actorId(),
            'seat_offer_assignment_id' => $result['assignment_id'],
            'history' => array_merge((array) $entry->history, [[
                'event' => 'ACCEPTED', 'at' => now()->toIso8601String(), 'assignment_id' => $result['assignment_id'],
            ]]),
        ])->save();

        $request->forceFill(['status' => 'ASSIGNED', 'created_assignment_id' => $result['assignment_id']])->save();

        $this->context->publish('TransportationAssignmentCreated', $entry, [
            'student_id' => $entry->student_id, 'route_id' => $entry->route_id, 'from_waitlist' => true,
        ]);

        return ['accepted' => true, 'waitlist_id' => $entry->id, 'assignment_id' => $result['assignment_id']];
    }

    public function declineOffer(int $waitlistId, ?string $reason = null): array
    {
        $entry = TransportationWaitlist::query()->findOrFail($waitlistId);
        $entry->forceFill([
            'status' => 'DECLINED',
            'history' => array_merge((array) $entry->history, [[
                'event' => 'DECLINED', 'at' => now()->toIso8601String(), 'reason' => $reason,
            ]]),
        ])->save();

        $this->context->audit('WaitlistOfferDeclined', $entry, ['reason' => $reason]);

        return ['declined' => true, 'waitlist_id' => $entry->id];
    }

    public function waitlist(array $filters = []): array
    {
        return TransportationWaitlist::query()
            ->when($filters['route_id'] ?? null, fn ($q, $routeId) => $q->where('route_id', $routeId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->orderByDesc('priority_score')->orderBy('requested_at')
            ->get()
            ->map(fn (TransportationWaitlist $entry): array => [
                'id' => $entry->id,
                'student_id' => $entry->student_id,
                'route_id' => $entry->route_id,
                'priority_score' => (int) $entry->priority_score,
                'priority_class' => $entry->priority_class,
                'special_need' => (bool) $entry->special_need,
                'boarding_status' => (bool) $entry->boarding_status,
                'status' => $entry->status,
                'requested_at' => $entry->requested_at,
                'offer_expires_at' => $entry->offer_expires_at,
            ])
            ->all();
    }

    public function requests(array $filters = []): array
    {
        return TransportationRequest::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->when($filters['student_id'] ?? null, fn ($q, $studentId) => $q->where('student_id', $studentId))
            ->when($filters['route_id'] ?? null, fn ($q, $routeId) => $q->where('route_id', $routeId))
            ->orderByDesc('id')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get()
            ->map(fn (TransportationRequest $request): array => [
                'id' => $request->id,
                'request_number' => $request->request_number,
                'request_type' => $request->request_type,
                'student_id' => $request->student_id,
                'route_id' => $request->route_id,
                'status' => $request->status,
                'priority_level' => $request->priority_level,
                'created_at' => $request->created_at,
                'capacity_check' => $request->capacity_check,
                'workflow_reference' => $request->workflow_reference,
            ])
            ->all();
    }
}
