<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Contracts\StudentProviderInterface;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\MembershipAction;
use App\Modules\Activity\Domain\Enums\MembershipRole;
use App\Modules\Activity\Domain\Enums\MembershipStatus;
use App\Modules\Activity\Domain\Enums\RegistrationStatus;
use App\Modules\Activity\Domain\Enums\TransferStatus;
use App\Modules\Activity\Domain\Enums\WithdrawalStatus;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityMembershipHistory;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityProgress;
use App\Modules\Activity\Models\ActivityRegistration;
use App\Modules\Activity\Models\ActivityTransfer;
use App\Modules\Activity\Models\ActivityWaitlistEntry;
use App\Modules\Activity\Models\ActivityWithdrawal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Keanggotaan — kondisi "benar-benar menjadi anggota" (§26, §72–75).
 *
 * Riwayat tidak pernah ditimpa. Siswa yang berhenti, ditangguhkan, berpindah
 * aktivitas, atau menyelesaikan periode meninggalkan baris keanggotaan yang
 * tetap dapat dibaca bertahun-tahun kemudian — inilah bahan portofolio dan
 * transkrip aktivitas.
 */
class ActivityMembershipService
{
    public function __construct(
        private readonly ActivityAuditService $audit,
        private readonly ActivityNotificationService $notifications,
        private readonly ActivityAccessService $access,
        private readonly ActivityIntegrationService $integrations,
        private readonly StudentProviderInterface $students,
    ) {}

    /** @return LengthAwarePaginator */
    public function paginate(array $filters = [], ?User $viewer = null): LengthAwarePaginator
    {
        $query = ActivityMembership::query()
            ->with(['program:id,name,code,period_id', 'period:id,name', 'student:id,full_name,nis'])
            ->when(isset($filters['program_id']), fn ($q) => $q->where('program_id', $filters['program_id']))
            ->when(isset($filters['period_id']), fn ($q) => $q->where('period_id', $filters['period_id']))
            ->when(isset($filters['student_id']), fn ($q) => $q->where('student_id', $filters['student_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['role']), fn ($q) => $q->where('role', $filters['role']))
            ->orderByDesc('joined_at');

        if ($viewer !== null && ! $viewer->can('activity.membership.view')) {
            $studentIds = $this->access->visibleStudentIds($viewer);
            $query->whereIn('student_id', $studentIds === [] ? [-1] : $studentIds);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * Jadikan pendaftaran sebagai keanggotaan (§26).
     *
     * Dipanggil setelah pendaftaran berstatus APPROVED. Kunci baris program
     * diambil lebih dahulu sehingga dua persetujuan bersamaan tidak melampaui
     * kapasitas; bila kursi habis, pendaftar **tidak** dibuatkan keanggotaan
     * dan keputusannya dilaporkan sebagai bentrok kapasitas.
     */
    public function createFromRegistration(ActivityRegistration $registration, ?User $actor = null, array $overrides = []): ActivityMembership
    {
        if (! in_array($registration->status, [RegistrationStatus::APPROVED, RegistrationStatus::SELECTED], true)) {
            throw ActivityConflictException::make(
                'Pendaftaran berstatus '.$registration->status->label().' — keanggotaan hanya dibuat untuk pendaftaran yang diterima.',
            );
        }

        $existing = ActivityMembership::query()->where('registration_id', $registration->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($registration, $actor, $overrides) {
            $program = ActivityProgram::query()->lockForUpdate()->findOrFail($registration->program_id);

            $active = ActivityMembership::query()
                ->where('program_id', $program->id)
                ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
                ->count();

            if ($program->maximum_capacity !== null && $active >= (int) $program->maximum_capacity) {
                throw ActivityConflictException::make(
                    'Kursi program sudah penuh ('.$active.'/'.$program->maximum_capacity.').',
                    ['capacity' => ['active' => $active, 'maximum' => (int) $program->maximum_capacity]],
                );
            }

            $membership = ActivityMembership::query()->create([
                'program_id' => $program->id,
                'period_id' => $registration->period_id ?? $program->period_id,
                'student_id' => $registration->student_id,
                'registration_id' => $registration->id,
                'role' => $overrides['role'] ?? MembershipRole::MEMBER->value,
                'status' => MembershipStatus::ACTIVE->value,
                'joined_at' => $overrides['joined_at'] ?? now()->toDateString(),
                'leadership_eligible' => $overrides['leadership_eligible'] ?? true,
                'metadata' => $overrides['metadata'] ?? null,
            ]);

            $this->writeHistory($membership, MembershipAction::CREATED, null, $overrides['notes'] ?? 'Dibuat dari pendaftaran '.$registration->code.'.', $actor);

            ActivityProgress::query()->firstOrCreate(
                ['membership_id' => $membership->id],
                [
                    'student_id' => $membership->student_id,
                    'program_id' => $membership->program_id,
                    'period_id' => $membership->period_id,
                    'status' => 'ACTIVE',
                ],
            );

            $this->audit->record(AuditAction::MEMBERSHIP_CREATED, [
                'subject' => $membership,
                'program_id' => $membership->program_id,
                'student_id' => $membership->student_id,
                'after' => $membership,
                'actor' => $actor?->id,
            ]);

            $this->syncLearning($program, [(int) $membership->student_id]);
            $this->notifications->registrationDecided($registration->refresh(), 'APPROVE');
            $this->promoteWaitlistIfSeatAvailable($program);

            return $membership->refresh();
        });
    }

    /** Pendaftaran langsung oleh admin (impor data lama, siswa pindahan). */
    public function createDirect(ActivityProgram $program, int $studentId, array $attributes, User $actor): ActivityMembership
    {
        $existing = ActivityMembership::query()
            ->where('program_id', $program->id)
            ->where('student_id', $studentId)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($program, $studentId, $attributes, $actor) {
            $locked = ActivityProgram::query()->lockForUpdate()->findOrFail($program->id);

            if ($locked->maximum_capacity !== null) {
                $active = ActivityMembership::query()
                    ->where('program_id', $locked->id)
                    ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
                    ->count();

                if ($active >= (int) $locked->maximum_capacity && ! ($attributes['allow_over_capacity'] ?? false)) {
                    throw ActivityConflictException::make('Kursi program sudah penuh — gunakan daftar tunggu atau ubah kapasitas.');
                }
            }

            $membership = ActivityMembership::query()->create([
                'program_id' => $locked->id,
                'period_id' => $attributes['period_id'] ?? $locked->period_id,
                'student_id' => $studentId,
                'role' => $attributes['role'] ?? MembershipRole::MEMBER->value,
                'status' => MembershipStatus::ACTIVE->value,
                'joined_at' => $attributes['joined_at'] ?? now()->toDateString(),
                'metadata' => $attributes['metadata'] ?? ['source' => 'admin'],
            ]);

            $this->writeHistory($membership, MembershipAction::CREATED, null, $attributes['notes'] ?? 'Ditambahkan oleh pengelola aktivitas.', $actor);
            $this->audit->record(AuditAction::MEMBERSHIP_CREATED, [
                'subject' => $membership,
                'program_id' => $membership->program_id,
                'student_id' => $membership->student_id,
                'after' => $membership,
                'actor' => $actor->id,
            ]);

            $this->syncLearning($locked, [$studentId]);

            return $membership->refresh();
        });
    }

    public function suspend(ActivityMembership $membership, User $actor, string $reason): ActivityMembership
    {
        if (trim($reason) === '') {
            throw ActivityValidationException::withErrors(['Alasan penangguhan wajib diisi.']);
        }

        if ($membership->status !== MembershipStatus::ACTIVE) {
            throw ActivityConflictException::make('Hanya keanggotaan aktif yang dapat ditangguhkan.');
        }

        return DB::transaction(function () use ($membership, $actor, $reason) {
            $before = $membership->attributesToArray();

            $membership->forceFill([
                'status' => MembershipStatus::SUSPENDED->value,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ])->save();

            $this->writeHistory($membership, MembershipAction::SUSPENDED, $reason, null, $actor);

            $this->audit->record(AuditAction::MEMBERSHIP_SUSPENDED, [
                'subject' => $membership,
                'program_id' => $membership->program_id,
                'student_id' => $membership->student_id,
                'before' => $before,
                'after' => $membership,
                'metadata' => ['reason' => $reason],
                'actor' => $actor->id,
            ]);

            return $membership->refresh();
        });
    }

    public function reactivate(ActivityMembership $membership, User $actor, ?string $reason = null): ActivityMembership
    {
        if (! in_array($membership->status, [MembershipStatus::SUSPENDED, MembershipStatus::INACTIVE], true)) {
            throw ActivityConflictException::make('Keanggotaan ini tidak dalam keadaan ditangguhkan.');
        }

        return DB::transaction(function () use ($membership, $actor, $reason) {
            $before = $membership->attributesToArray();

            $membership->forceFill([
                'status' => MembershipStatus::ACTIVE->value,
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();

            $this->writeHistory($membership, MembershipAction::REACTIVATED, $reason ?? 'Diaktifkan kembali.', null, $actor);

            $this->audit->record(AuditAction::MEMBERSHIP_REACTIVATED, [
                'subject' => $membership,
                'program_id' => $membership->program_id,
                'student_id' => $membership->student_id,
                'before' => $before,
                'after' => $membership,
                'actor' => $actor->id,
            ]);

            $this->syncLearning($membership->program, [(int) $membership->student_id]);

            return $membership->refresh();
        });
    }

    public function changeRole(ActivityMembership $membership, User $actor, string $role, ?string $reason = null): ActivityMembership
    {
        if (! in_array($role, MembershipRole::values(), true)) {
            throw ActivityValidationException::withErrors(['Peran anggota tidak dikenal: '.$role.'.']);
        }

        $before = $membership->attributesToArray();
        $fromRole = $membership->role?->value;

        $membership->forceFill(['role' => $role])->save();

        $this->writeHistory($membership, MembershipAction::ROLE_CHANGED, $reason, ['role' => $role], $actor, $fromRole);

        $this->audit->record(AuditAction::MEMBERSHIP_ROLE_CHANGED, [
            'subject' => $membership,
            'program_id' => $membership->program_id,
            'student_id' => $membership->student_id,
            'before' => $before,
            'after' => $membership,
            'actor' => $actor->id,
        ]);

        return $membership->refresh();
    }

    /** Ajukan pengunduran diri (§72). */
    public function requestWithdrawal(ActivityMembership $membership, User $actor, array $payload = []): ActivityWithdrawal
    {
        if ($membership->status->isFinished()) {
            throw ActivityConflictException::make('Keanggotaan sudah berakhir — tidak ada yang perlu diundurkan.');
        }

        $withdrawal = ActivityWithdrawal::query()->firstOrCreate(
            ['idempotency_key' => 'withdrawal|'.$membership->id.'|'.($payload['idempotency_key'] ?? now()->timestamp)],
            [
                'membership_id' => $membership->id,
                'student_id' => $membership->student_id,
                'program_id' => $membership->program_id,
                'reason_code' => $payload['reason_code'] ?? null,
                'reason' => $payload['reason'] ?? null,
                'status' => WithdrawalStatus::PENDING->value,
                'requested_by' => $actor->id,
                'requested_at' => now(),
                'effective_immediately' => (bool) ($payload['effective_immediately'] ?? false),
            ],
        );

        if ($withdrawal->wasRecentlyCreated && ($payload['auto_approve'] ?? false) === true && $actor->can('activity.membership.manage')) {
            return $this->decideWithdrawal($withdrawal, $actor, 'APPROVE', $payload['notes'] ?? 'Disetujui otomatis oleh pengelola.');
        }

        return $withdrawal;
    }

    public function decideWithdrawal(ActivityWithdrawal $withdrawal, User $actor, string $decision, ?string $notes = null): ActivityWithdrawal
    {
        $decision = $this->normaliseDecision($decision);

        if (! in_array($decision, [WithdrawalStatus::APPROVED->value, WithdrawalStatus::REJECTED->value], true)) {
            throw ActivityValidationException::withErrors(['Keputusan pengunduran diri harus APPROVED atau REJECTED.']);
        }

        if (! $actor->can('activity.membership.manage')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang memutuskan pengunduran diri.');
        }

        return DB::transaction(function () use ($withdrawal, $actor, $decision, $notes) {
            $locked = ActivityWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if ($locked->status !== WithdrawalStatus::PENDING) {
                throw ActivityConflictException::make('Pengunduran diri ini sudah diputuskan ('.$locked->status->label().').');
            }

            $locked->forceFill([
                'status' => $decision,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_notes' => $notes,
            ])->save();

            $this->audit->record(AuditAction::MEMBERSHIP_WITHDRAWN, [
                'subject' => $locked,
                'program_id' => $locked->program_id,
                'student_id' => $locked->student_id,
                'after' => ['decision' => $decision, 'notes' => $notes],
                'actor' => $actor->id,
            ]);

            if ($decision === WithdrawalStatus::APPROVED->value) {
                $membership = ActivityMembership::query()->lockForUpdate()->findOrFail($locked->membership_id);
                $before = $membership->attributesToArray();

                $membership->forceFill([
                    'status' => MembershipStatus::WITHDRAWN->value,
                    'ended_at' => now()->toDateString(),
                    'withdrawal_reason' => $locked->reason ?? $notes,
                ])->save();

                $this->writeHistory($membership, MembershipAction::WITHDRAWN, $locked->reason ?? $notes, null, $actor);

                ActivityProgress::query()->where('membership_id', $membership->id)
                    ->update(['status' => 'COMPLETED', 'calculated_at' => now(), 'updated_at' => now()]);

                $this->syncLearning($membership->program, [(int) $membership->student_id], unenroll: true);
                $this->promoteWaitlistIfSeatAvailable($membership->program);

                $this->notifications->notify([
                    'event' => 'WITHDRAWAL_DECIDED',
                    'program_id' => $membership->program_id,
                    'student_id' => $membership->student_id,
                    'body' => 'Pengunduran diri dari '.($membership->program?->name ?? 'aktivitas').' telah disetujui.',
                    'marker' => 'withdrawal|'.$locked->id,
                ]);
            }

            return $locked->refresh();
        });
    }

    /** Ajukan pindah aktivitas (§73). Keanggotaan lama ditutup, bukan ditimpa. */
    public function requestTransfer(User $actor, ActivityMembership $from, ActivityProgram $to, array $payload = []): ActivityTransfer
    {
        $studentId = (int) $from->student_id;

        if ($to->id === $from->program_id) {
            throw ActivityValidationException::withErrors(['Program tujuan sama dengan program asal.']);
        }

        $transfer = ActivityTransfer::query()->firstOrCreate(
            ['idempotency_key' => 'transfer|'.$from->id.'|'.$to->id.'|'.($payload['idempotency_key'] ?? now()->timestamp)],
            [
                'student_id' => $studentId,
                'from_membership_id' => $from->id,
                'to_program_id' => $to->id,
                'status' => TransferStatus::PENDING->value,
                'reason' => $payload['reason'] ?? null,
                'requested_by' => $actor->id,
                'requested_at' => now(),
            ],
        );

        return $transfer;
    }

    public function decideTransfer(ActivityTransfer $transfer, User $actor, string $decision, ?string $notes = null): ActivityTransfer
    {
        $decision = $this->normaliseDecision($decision);

        if (! in_array($decision, [TransferStatus::APPROVED->value, TransferStatus::REJECTED->value], true)) {
            throw ActivityValidationException::withErrors(['Keputusan pindah aktivitas harus APPROVED atau REJECTED.']);
        }

        if (! $actor->can('activity.membership.manage')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang memutuskan pindah aktivitas.');
        }

        return DB::transaction(function () use ($transfer, $actor, $decision, $notes) {
            $locked = ActivityTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($locked->status !== TransferStatus::PENDING) {
                throw ActivityConflictException::make('Permohonan pindah ini sudah diputuskan.');
            }

            if ($decision === TransferStatus::APPROVED->value) {
                $target = ActivityProgram::query()->lockForUpdate()->findOrFail($locked->to_program_id);

                $active = ActivityMembership::query()
                    ->where('program_id', $target->id)
                    ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
                    ->count();

                if ($target->maximum_capacity !== null && $active >= (int) $target->maximum_capacity) {
                    throw ActivityConflictException::make('Program tujuan sudah penuh — pindah aktivitas tidak dapat disetujui sekarang.');
                }

                $from = ActivityMembership::query()->lockForUpdate()->findOrFail($locked->from_membership_id);

                $from->forceFill([
                    'status' => MembershipStatus::WITHDRAWN->value,
                    'ended_at' => now()->toDateString(),
                    'withdrawal_reason' => 'Pindah ke '.$target->name,
                ])->save();

                $this->writeHistory($from, MembershipAction::TRANSFERRED, 'Pindah ke '.$target->name, null, $actor);

                $newMembership = $this->createDirect($target, (int) $locked->student_id, [
                    'role' => MembershipRole::MEMBER->value,
                    'joined_at' => now()->toDateString(),
                    'notes' => 'Pindah dari '.($from->program?->name ?? 'aktivitas sebelumnya').'.',
                ], $actor);

                $locked->forceFill([
                    'status' => TransferStatus::APPROVED->value,
                    'to_membership_id' => $newMembership->id,
                    'conflict_snapshot' => ['capacity' => $active, 'maximum' => $target->maximum_capacity],
                ])->save();

                $this->syncLearning($from->program, [(int) $locked->student_id], unenroll: true);
                $this->promoteWaitlistIfSeatAvailable($from->program);
            } else {
                $locked->forceFill(['status' => TransferStatus::REJECTED->value])->save();
            }

            $locked->forceFill(['decided_by' => $actor->id, 'decided_at' => now(), 'decision_notes' => $notes])->save();

            $this->audit->record(AuditAction::TRANSFER_APPROVED, [
                'subject' => $locked,
                'program_id' => $locked->to_program_id,
                'student_id' => $locked->student_id,
                'after' => ['decision' => $decision],
                'actor' => $actor->id,
            ]);

            $this->notifications->notify([
                'event' => 'TRANSFER_DECIDED',
                'program_id' => $locked->to_program_id,
                'student_id' => $locked->student_id,
                'body' => 'Permohonan pindah aktivitas '.$locked->status->label().'.',
                'marker' => 'transfer|'.$locked->id,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Promosikan anggota dari daftar tunggu saat kursi terbuka (idempoten).
     *
     * Kunci `promotion_key` memastikan satu entri hanya dipromosikan sekali,
     * meskipun proses dijalankan dua kali (perintah terjadwal × aksi pengguna).
     */
    public function promoteWaitlistIfSeatAvailable(ActivityProgram $program): ?ActivityMembership
    {
        $capacity = ActivityProgram::query()->lockForUpdate()->find($program->id);

        if ($capacity === null || $capacity->maximum_capacity === null) {
            return null;
        }

        $active = ActivityMembership::query()
            ->where('program_id', $capacity->id)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->count();

        if ($active >= (int) $capacity->maximum_capacity) {
            return null;
        }

        $entry = ActivityWaitlistEntry::query()
            ->where('program_id', $capacity->id)
            ->where('status', 'WAITING')
            ->orderBy('position')
            ->lockForUpdate()
            ->first();

        if ($entry === null) {
            return null;
        }

        $promotionKey = 'promotion|'.$entry->id.'|'.now()->format('YmdH');

        if ($entry->promotion_key === $promotionKey) {
            return null;
        }

        $registration = $entry->registration;

        if ($registration === null) {
            return null;
        }

        $registration->forceFill(['status' => RegistrationStatus::APPROVED->value, 'decided_at' => now()])->save();

        $membership = $this->createFromRegistration($registration, null, ['notes' => 'Dipromosikan dari daftar tunggu.']);

        $entry->forceFill([
            'status' => 'PROMOTED',
            'promoted_at' => now(),
            'promoted_membership_id' => $membership->id,
            'promotion_key' => $promotionKey,
        ])->save();

        $this->audit->record(AuditAction::WAITLIST_PROMOTED, [
            'subject' => $entry,
            'program_id' => $capacity->id,
            'student_id' => $entry->student_id,
            'after' => ['position' => $entry->position, 'membership_id' => $membership->id],
        ]);

        $this->notifications->registrationDecided($registration->refresh(), 'APPROVE');

        return $membership;
    }

    /** @return list<array> */
    public function histories(ActivityMembership $membership): array
    {
        return $membership->histories()
            ->with('actor:id,name')
            ->get()
            ->map(fn (ActivityMembershipHistory $row) => [
                'action' => $row->action?->value,
                'action_label' => $row->action?->label(),
                'from_status' => $row->from_status,
                'to_status' => $row->to_status,
                'reason' => $row->reason,
                'actor' => $row->actor?->name,
                'at' => $row->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Keanggotaan aktif beserta progres (dipakai portal siswa/orang tua). */
    public function activeFor(int $studentId): array
    {
        return ActivityMembership::query()
            ->with(['program:id,name,code,category_id', 'period:id,name', 'progress'])
            ->where('student_id', $studentId)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->get()
            ->map(fn (ActivityMembership $membership) => [
                'id' => (int) $membership->id,
                'program_id' => (int) $membership->program_id,
                'program' => $membership->program?->name,
                'period' => $membership->period?->name,
                'role' => $membership->role?->value,
                'role_label' => $membership->role?->label(),
                'status' => $membership->status?->value,
                'status_label' => $membership->status?->label(),
                'joined_at' => $membership->joined_at?->toDateString(),
                'attendance_percentage' => $membership->progress?->attendance_percentage === null ? null : (float) $membership->progress->attendance_percentage,
                'average_score' => $membership->progress?->average_score === null ? null : (float) $membership->progress->average_score,
            ])
            ->all();
    }

    public function studentName(int $studentId): ?string
    {
        return $this->students->snapshot($studentId)['name'] ?? null;
    }

    /** Terima bentuk ringkas (APPROVE/REJECT) maupun penuh (APPROVED/REJECTED). */
    protected function normaliseDecision(string $decision): string
    {
        return match (strtoupper($decision)) {
            'APPROVE' => WithdrawalStatus::APPROVED->value,
            'REJECT' => WithdrawalStatus::REJECTED->value,
            default => strtoupper($decision),
        };
    }

    protected function writeHistory(
        ActivityMembership $membership,
        MembershipAction $action,
        ?string $reason = null,
        ?string $notes = null,
        ?User $actor = null,
        ?string $fromRole = null,
    ): ActivityMembershipHistory {
        return ActivityMembershipHistory::query()->create([
            'membership_id' => $membership->id,
            'action' => $action->value,
            'from_status' => null,
            'to_status' => $membership->status?->value,
            'from_role' => $fromRole,
            'to_role' => $membership->role?->value,
            'reason' => $reason ?? $notes,
            'actor_user_id' => $actor?->id,
        ]);
    }

    /** Selaraskan keanggotaan dengan kursus LMS program, bila ada. */
    protected function syncLearning(ActivityProgram $program, array $studentIds, bool $unenroll = false): void
    {
        if ($program->lms_course_id === null) {
            return;
        }

        $courseId = (int) $program->lms_course_id;

        $this->integrations->call('LMS', $unenroll ? 'unenroll' : 'enroll', [
            'subject_type' => ActivityProgram::class,
            'subject_id' => $program->id,
            'course_id' => $courseId,
            'students' => $studentIds,
        ], fn () => $unenroll
            ? $this->integrations->lms()->unenroll($courseId, $studentIds)
            : $this->integrations->lms()->enroll($courseId, $studentIds));
    }
}
