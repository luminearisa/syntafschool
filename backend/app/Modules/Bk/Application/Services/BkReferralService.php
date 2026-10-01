<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Contracts\StudentProviderInterface;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\Priority;
use App\Modules\Bk\Domain\Enums\ReferralCategory;
use App\Modules\Bk\Domain\Enums\ReferralSource;
use App\Modules\Bk\Domain\Enums\ReferralStatus;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\ScreeningOutcome;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkReferral;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Referral — pintu masuk layanan BK.
 *
 * Alur baku: SUBMITTED → RECEIVED (koordinator) → SCREENING → ASSIGNED →
 * IN_PROGRESS, lalu berakhir di RESOLVED, atau berhenti sebagai REJECTED /
 * CANCELLED. Setiap perpindahan status dicatat di timeline dan jejak audit.
 *
 * Guru, siswa, dan orang tua boleh **mengajukan**; hanya staf BK yang boleh
 * menerima, menyaring, dan menugaskan.
 */
class BkReferralService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
        private readonly StudentProviderInterface $students,
    ) {
    }

    /**
     * Buat referral baru.
     *
     * Pelapor tidak perlu menuliskan detail sensitif: `description` cukup apa
     * yang ia amati, dan bila keberatan menyebut identitas, `confidentiality`
     * dapat dinaikkan.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): BkReferral
    {
        $studentId = (int) $data['student_id'];

        if ($this->students->snapshot($studentId) === null) {
            throw new BkValidationException(['student_id' => ['Siswa tidak ditemukan.']]);
        }

        $source = ReferralSource::from($data['source_type'] ?? ReferralSource::TEACHER->value);

        // Siswa/orang tua tidak dapat merujuk siswa lain.
        if (in_array($source, [ReferralSource::SELF_REFERRAL, ReferralSource::STUDENT], true)) {
            $own = $this->access->studentIdForUser($actor);

            if ($own !== null && $own !== $studentId) {
                throw new BkValidationException(['student_id' => ['Siswa hanya dapat mengajukan untuk dirinya sendiri.']]);
            }
        }

        if ($source === ReferralSource::PARENT && ! $this->access->isBkStaff($actor) && ! $this->access->isGuardianOf($actor, $studentId)) {
            throw new BkValidationException(['student_id' => ['Anda bukan wali sah dari siswa tersebut.']]);
        }

        return DB::transaction(function () use ($actor, $data, $studentId, $source): BkReferral {
            $referral = BkReferral::query()->create([
                'referral_number' => $this->numbers->next('BK_REFERRAL', 'BK-R'),
                'student_id' => $studentId,
                'source_type' => $source->value,
                'source_id' => $data['source_id'] ?? null,
                'source_label' => $data['source_label'] ?? null,
                'category' => ($data['category'] ?? ReferralCategory::OTHER->value),
                'priority' => ($data['priority'] ?? Priority::NORMAL->value),
                'description' => $data['description'],
                'observation' => $data['observation'] ?? null,
                'requested_support' => $data['requested_support'] ?? null,
                'confidentiality_level' => $data['confidentiality_level'] ?? ConfidentialityLevel::RESTRICTED->value,
                'referred_by' => $actor->id,
                'referred_by_role' => $actor->roles->pluck('name')->first(),
                'referred_at' => now(),
                'status' => ReferralStatus::SUBMITTED->value,
                'consent_required' => (bool) ($data['consent_required'] ?? false),
                'meta' => $data['meta'] ?? null,
            ]);

            $this->timeline->record(
                CaseEventType::REFERRAL_CREATED,
                $actor,
                referral: $referral,
                summary: 'Referral dibuat oleh '.$source->label().'.',
                payload: ['category' => $referral->category->value, 'priority' => $referral->priority->value],
            );

            $this->audit->record(AuditAction::CREATE, $referral, $actor, ['student_id' => $studentId]);
            $this->notifications->notifyTeam(
                NotificationEvent::NEW_REFERRAL,
                sprintf('Referral baru %s (%s) menunggu pemeriksaan.', $referral->referral_number, $referral->category->label()),
                ['referral_id' => $referral->id, 'student_id' => $studentId, 'priority' => $referral->priority->value],
            );

            return $referral->refresh();
        });
    }

    /** Terima referral: menjadi tanggung jawab BK. */
    public function receive(User $actor, BkReferral $referral, ?string $note = null): BkReferral
    {
        $this->assertNotTerminal($referral);

        if (! in_array($referral->status, [ReferralStatus::SUBMITTED, ReferralStatus::RECEIVED], true)) {
            throw new BkValidationException(['status' => ['Referral ini sudah diproses lebih lanjut.']]);
        }

        return DB::transaction(function () use ($actor, $referral, $note): BkReferral {
            $from = $referral->status->value;

            $referral->forceFill([
                'status' => ReferralStatus::RECEIVED->value,
                'received_by' => $actor->id,
                'received_at' => now(),
                'resolution_note' => $note ?? $referral->resolution_note,
            ])->save();

            $this->timeline->recordTransition($referral, $from, ReferralStatus::RECEIVED->value, $actor, CaseEventType::REFERRAL_RECEIVED);
            $this->audit->record(AuditAction::UPDATE, $referral, $actor, ['changes' => ['status' => ['from' => $from, 'to' => 'RECEIVED']]]);

            return $referral->refresh();
        });
    }

    /**
     * Buka kasus dari referral (setelah screening).
     *
     * Kasus mewarisi kategori, prioritas, tingkat kerahasiaan, dan siswa dari
     * referral; referral menunjuk balik ke kasusnya sehingga rantai asal-usul
     * tidak pernah putus.
     */
    public function openCase(User $actor, BkReferral $referral, array $data = []): BkCase
    {
        if ($referral->case_id !== null) {
            throw new BkValidationException(['case' => ['Referral ini sudah memiliki kasus.']]);
        }

        $priority = $data['priority'] ?? $referral->priority;

        if (is_string($priority)) {
            $priority = \App\Modules\Bk\Domain\Enums\Priority::from($priority);
        }

        return DB::transaction(function () use ($actor, $referral, $data, $priority): BkCase {
            $case = BkCase::query()->create([
                'case_number' => $this->numbers->next('BK_CASE', 'BK-C'),
                'student_id' => $referral->student_id,
                'case_type' => $data['case_type'],
                'category' => $data['category'] ?? $referral->category->value,
                'title' => $data['title'] ?? null,
                'priority' => $priority->value,
                'severity' => $data['severity'] ?? \App\Modules\Bk\Domain\Enums\Severity::fromPriority($priority)->value,
                'confidentiality_level' => $data['confidentiality_level'] ?? $referral->confidentiality_level->value,
                'source_referral_id' => $referral->id,
                'coordinator_id' => $data['coordinator_id'] ?? $actor->id,
                'opened_at' => now(),
                'target_review_date' => $data['target_review_date'] ?? now()->addDays($priority->defaultReviewDays())->toDateString(),
                'status' => 'OPEN',
                'created_by' => $actor->id,
            ]);

            $from = $referral->status->value;
            $referral->forceFill(['case_id' => $case->id, 'status' => ReferralStatus::ASSIGNED->value, 'assigned_at' => now()])->save();

            $this->timeline->record(CaseEventType::CASE_OPENED, $actor, $case, $referral, 'Kasus dibuka dari referral '.$referral->referral_number.'.');
            $this->timeline->recordTransition($referral, $from, ReferralStatus::ASSIGNED->value, $actor, CaseEventType::CASE_ASSIGNED, 'Referral menjadi kasus '.$case->case_number.'.');
            $this->audit->record(AuditAction::CREATE, $case, $actor, ['referral_id' => $referral->id, 'student_id' => $referral->student_id]);

            return $case->refresh();
        });
    }

    /** Tandai referral sebagai sudah disaring tanpa membuka kasus (mis. dipantau). */
    public function recordScreeningOutcome(User $actor, BkReferral $referral, ScreeningOutcome $outcome): BkReferral
    {
        $status = match ($outcome) {
            ScreeningOutcome::NO_ACTION => ReferralStatus::RESOLVED,
            ScreeningOutcome::URGENT_ESCALATION, ScreeningOutcome::TEAM_REVIEW => ReferralStatus::SCREENING,
            default => ReferralStatus::SCREENING,
        };

        $from = $referral->status->value;

        $referral->forceFill([
            'status' => $status->value,
            'screened_at' => now(),
            'resolution_note' => $outcome->label(),
        ])->save();

        $this->timeline->record(
            CaseEventType::SCREENING_COMPLETED,
            $actor,
            referral: $referral,
            summary: 'Screening selesai: '.$outcome->label().'.',
            statusFrom: $from,
            statusTo: $status->value,
            payload: ['outcome' => $outcome->value],
        );

        return $referral->refresh();
    }

    public function reject(User $actor, BkReferral $referral, string $reason): BkReferral
    {
        $this->assertNotTerminal($referral);

        $from = $referral->status->value;

        $referral->forceFill([
            'status' => ReferralStatus::REJECTED->value,
            'rejection_reason' => $reason,
            'closed_at' => now(),
        ])->save();

        $this->timeline->record(CaseEventType::STATUS_CHANGED, $actor, referral: $referral,
            summary: 'Referral ditolak: '.$reason, statusFrom: $from, statusTo: ReferralStatus::REJECTED->value);
        $this->audit->record(AuditAction::UPDATE, $referral, $actor, ['changes' => ['status' => ['from' => $from, 'to' => 'REJECTED']]]);

        return $referral->refresh();
    }

    /** Pelapor/koordinator membatalkan referral. */
    public function cancel(User $actor, BkReferral $referral, string $reason): BkReferral
    {
        $this->assertNotTerminal($referral);

        if (! $this->access->isBkStaff($actor) && (int) $referral->referred_by !== (int) $actor->id) {
            throw new BkValidationException(['referral' => ['Hanya pelapor atau staf BK yang dapat membatalkan referral.']]);
        }

        $from = $referral->status->value;

        $referral->forceFill([
            'status' => ReferralStatus::CANCELLED->value,
            'cancellation_reason' => $reason,
            'closed_at' => now(),
        ])->save();

        $this->timeline->record(CaseEventType::STATUS_CHANGED, $actor, referral: $referral,
            summary: 'Referral dibatalkan: '.$reason, statusFrom: $from, statusTo: ReferralStatus::CANCELLED->value);

        return $referral->refresh();
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = BkReferral::query()->with(['student:id,full_name,nis', 'case:id,case_number,status']);

        $this->access->scopeReferrals($query, $actor);

        return $query
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source_type', $source))
            ->when($filters['student_id'] ?? null, fn ($q, $studentId) => $q->where('student_id', $studentId))
            ->when(filter_var($filters['queue'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->queue())
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(function ($inner) use ($term): void {
                $inner->where('referral_number', 'like', '%'.$term.'%')
                    ->orWhereHas('student', fn ($s) => $s->where('full_name', 'like', '%'.$term.'%'));
            }))
            ->orderByRaw("CASE priority WHEN 'URGENT' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'NORMAL' THEN 3 ELSE 4 END")
            ->orderByDesc('referred_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    private function assertNotTerminal(BkReferral $referral): void
    {
        if ($referral->status->isTerminal()) {
            throw new BkValidationException(['status' => ['Referral sudah selesai dan tidak dapat diubah lagi.']]);
        }
    }
}
