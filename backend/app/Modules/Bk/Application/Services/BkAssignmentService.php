<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\AssignmentType;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseAssignment;
use App\Modules\Bk\Models\BkCounselor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penugasan konselor & pemantauan beban kerja.
 *
 * Koordinator memutuskan siapa menangani kasus; modul ini memberinya bahan
 * keputusan yang jujur: beban berjalan tiap konselor, kapasitas yang
 * ditetapkan, dan peringatan ketika penugasan membuat seseorang kelebihan
 * beban. Penugasan lama tidak dihapus, hanya ditutup — sehingga riwayat tetap.
 */
class BkAssignmentService
{
    public function __construct(
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
    ) {
    }

    /**
     * Ringkasan beban kerja seluruh konselor aktif.
     *
     * @return list<array{counselor_id: ?int, user_id: int, name: string, level: ?string,
     *     max_active_cases: ?int, active_cases: int, urgent_cases: int, overdue_reviews: int,
     *     over_capacity: bool}>
     */
    public function workload(): array
    {
        $rows = BkCounselor::query()->with('user:id,name')->active()->get();

        $counts = BkCase::query()
            ->open()
            ->selectRaw(
                "assigned_counselor_id, COUNT(*) as active_cases, "
                ."SUM(CASE WHEN priority IN ('URGENT', 'HIGH') THEN 1 ELSE 0 END) as urgent_cases, "
                .'SUM(CASE WHEN target_review_date IS NOT NULL AND target_review_date < ? THEN 1 ELSE 0 END) as overdue_reviews',
                [now()->toDateString()],
            )
            ->groupBy('assigned_counselor_id')
            ->get()
            ->keyBy('assigned_counselor_id');

        return $rows->map(function (BkCounselor $counselor) use ($counts): array {
            $row = $counts->get($counselor->user_id);
            $active = (int) ($row->active_cases ?? 0);

            return [
                'counselor_id' => $counselor->id,
                'user_id' => (int) $counselor->user_id,
                'name' => (string) ($counselor->user?->name ?? '-'),
                'level' => $counselor->level,
                'max_active_cases' => (int) $counselor->max_active_cases,
                'active_cases' => $active,
                'urgent_cases' => (int) ($row->urgent_cases ?? 0),
                'overdue_reviews' => (int) ($row->overdue_reviews ?? 0),
                'over_capacity' => $active >= (int) $counselor->max_active_cases,
            ];
        })->all();
    }

    /**
     * Konselor paling lapang untuk kasus ini (bahan rekomendasi, bukan keputusan).
     *
     * @return Collection<int, array{user_id: int, name: string, active_cases: int, remaining: int}>
     */
    public function suggestCandidates(?int $excludeUserId = null): Collection
    {
        return collect($this->workload())
            ->filter(fn (array $row): bool => $row['user_id'] !== $excludeUserId && ! $row['over_capacity'])
            ->sortBy('active_cases')
            ->map(fn (array $row): array => [
                'user_id' => $row['user_id'],
                'name' => $row['name'],
                'active_cases' => $row['active_cases'],
                'remaining' => max(0, (int) $row['max_active_cases'] - $row['active_cases']),
            ])
            ->values();
    }

    /**
     * Tugaskan (atau gantikan) konselor utama sebuah kasus.
     *
     * `force` dipakai koordinator untuk menembus peringatan kelebihan beban;
     * keputusan itu selalu tercatat di jejak audit.
     */
    public function assign(User $actor, BkCase $case, int $counselorUserId, AssignmentType $type = AssignmentType::PRIMARY, bool $force = false): BkCase
    {
        $counselor = BkCounselor::query()->where('user_id', $counselorUserId)->active()->first();

        if ($counselor === null) {
            throw new BkValidationException(['assigned_counselor_id' => ['Pengguna ini bukan konselor aktif.']]);
        }

        $active = $counselor->activeCaseCount();

        if (! $force && $active >= (int) $counselor->max_active_cases) {
            throw new BkValidationException([
                'assigned_counselor_id' => [sprintf('%s sudah menangani %d kasus aktif (kapasitas %d). Gunakan penugasan paksa bila memang disengaja.', $counselor->user?->name ?? 'Konselor', $active, $counselor->max_active_cases)],
            ]);
        }

        return DB::transaction(function () use ($actor, $case, $counselor, $type, $active): BkCase {
            $previousCounselor = $case->assigned_counselor_id;
            $hadPrimary = BkCaseAssignment::query()->where('case_id', $case->id)->where('is_active', true)->exists();

            // Tutup penugasan aktif sebelumnya (riwayat tetap tersimpan).
            BkCaseAssignment::query()
                ->where('case_id', $case->id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'ended_at' => now(), 'end_reason' => 'Digantikan oleh penugasan baru.']);

            BkCaseAssignment::query()->create([
                'case_id' => $case->id,
                'user_id' => $counselor->user_id,
                'assigned_by' => $actor->id,
                'assignment_type' => $type->value,
                'assigned_at' => now(),
                'is_active' => true,
                'workload_at_assignment' => $active,
            ]);

            $updates = ['status' => $case->status->value];

            if ($type === AssignmentType::PRIMARY) {
                $updates['assigned_counselor_id'] = $counselor->user_id;
            }

            if ($case->status->value === 'OPEN') {
                $updates['status'] = 'ASSESSMENT';
            }

            $case->forceFill($updates)->save();

            // Bila konselor diperlakukan sebagai peserta kasus, catat sekali saja.
            $case->participants()->firstOrCreate(
                ['user_id' => $counselor->user_id, 'role' => $type === AssignmentType::PRIMARY ? 'COUNSELOR' : 'CO_COUNSELOR'],
                ['can_view_case' => true, 'max_confidentiality' => 'HIGHLY_CONFIDENTIAL', 'added_by' => $actor->id],
            );

            $this->timeline->record(
                $hadPrimary ? CaseEventType::CASE_REASSIGNED : CaseEventType::CASE_ASSIGNED,
                $actor,
                $case,
                summary: $hadPrimary
                    ? 'Penanganan dialihkan kepada '.($counselor->user?->name ?? 'konselor baru').'.'
                    : 'Kasus ditugaskan kepada '.($counselor->user?->name ?? 'konselor').'.',
                statusFrom: $previousCounselor === null ? null : $case->status->value,
                payload: ['counselor_user_id' => (int) $counselor->user_id, 'workload' => $active],
            );

            $this->audit->record(
                $hadPrimary ? \App\Modules\Bk\Domain\Enums\AuditAction::REASSIGN : \App\Modules\Bk\Domain\Enums\AuditAction::ASSIGN,
                $case,
                $actor,
                ['changes' => ['assigned_counselor_id' => ['from' => $previousCounselor, 'to' => $counselor->user_id]]],
            );

            $this->notifications->notifyCaseAssigned($case->refresh(), (int) $counselor->user_id);

            return $case->refresh();
        });
    }

    /** Tambahkan pendamping/supervisor tanpa mengubah konselor utama. */
    public function addSecondary(User $actor, BkCase $case, int $userId, AssignmentType $type = AssignmentType::SECONDARY): BkCaseAssignment
    {
        if (! in_array($type, [AssignmentType::SECONDARY, AssignmentType::SUPERVISOR, AssignmentType::TEMPORARY], true)) {
            throw new BkValidationException(['assignment_type' => ['Gunakan penugasan utama untuk konselor penanggung jawab.']]);
        }

        $assignment = BkCaseAssignment::query()->create([
            'case_id' => $case->id,
            'user_id' => $userId,
            'assigned_by' => $actor->id,
            'assignment_type' => $type->value,
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $case->participants()->firstOrCreate(
            ['user_id' => $userId, 'role' => $type === AssignmentType::SUPERVISOR ? 'COORDINATOR' : 'CO_COUNSELOR'],
            ['can_view_case' => true, 'max_confidentiality' => 'HIGHLY_CONFIDENTIAL', 'added_by' => $actor->id],
        );

        $this->timeline->record(CaseEventType::CASE_ASSIGNED, $actor, $case,
            summary: $type->label().' ditambahkan pada kasus.', payload: ['user_id' => $userId, 'type' => $type->value]);

        return $assignment;
    }

    /** Hentikan penugasan seseorang pada kasus (mis. pindah tugas). */
    public function endAssignment(User $actor, BkCase $case, int $userId, string $reason): void
    {
        $ended = BkCaseAssignment::query()
            ->where('case_id', $case->id)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->update(['is_active' => false, 'ended_at' => now(), 'end_reason' => $reason]);

        if ($ended === 0) {
            throw new BkValidationException(['assignment' => ['Tidak ada penugasan aktif untuk pengguna tersebut.']]);
        }

        if ((int) $case->assigned_counselor_id === $userId) {
            $case->forceFill(['assigned_counselor_id' => null])->save();
        }

        $this->timeline->record(CaseEventType::CASE_REASSIGNED, $actor, $case,
            summary: 'Penugasan dihentikan: '.$reason, payload: ['user_id' => $userId]);
        $this->audit->record(\App\Modules\Bk\Domain\Enums\AuditAction::REASSIGN, $case, $actor, ['reason' => $reason]);
    }

    /** Konselor menerima/menolak penugasan; penolakan mengembalikan ke koordinator. */
    public function declineAssignment(User $actor, BkCase $case, string $reason): BkCase
    {
        if ((int) $case->assigned_counselor_id !== (int) $actor->id) {
            throw new BkValidationException(['case' => ['Hanya konselor penanggung jawab yang dapat menolak penugasan ini.']]);
        }

        $from = $case->status->value;

        $case->forceFill(['assigned_counselor_id' => null, 'status' => 'OPEN'])->save();

        BkCaseAssignment::query()->where('case_id', $case->id)->where('user_id', $actor->id)->where('is_active', true)
            ->update(['is_active' => false, 'ended_at' => now(), 'end_reason' => $reason]);

        $this->timeline->record(CaseEventType::CASE_REASSIGNED, $actor, $case,
            summary: 'Penugasan ditolak oleh konselor: '.$reason, statusFrom: $from, statusTo: 'OPEN');

        $this->notifications->notifyTeam(
            NotificationEvent::CASE_ASSIGNED,
            sprintf('Kasus %s kembali ke antrean: %s', $case->case_number, $reason),
            ['case_id' => $case->id],
        );

        return $case->refresh();
    }
}
