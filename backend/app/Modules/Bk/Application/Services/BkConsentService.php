<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Contracts\ParentProviderInterface;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkConsent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persetujuan orang tua.
 *
 * Hanya wali yang sah — diperiksa ke `student_guardians` — yang dapat
 * memutuskan. Persetujuan punya masa berlaku; yang kedaluwarsa ditutup oleh
 * perintah terjadwal sehingga statusnya tidak pernah menipu.
 */
class BkConsentService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
        private readonly ParentProviderInterface $parents,
    ) {
    }

    /** Minta persetujuan orang tua. @param array<string, mixed> $data */
    public function request(User $actor, int $studentId, array $data): BkConsent
    {
        $guardianUserIds = $this->parents->guardianUserIdsForStudent($studentId);

        if ($guardianUserIds === [] && (bool) ($data['strict'] ?? false)) {
            throw new BkValidationException(['student_id' => ['Tidak ada akun wali aktif yang dapat dimintai persetujuan.']]);
        }

        $consent = BkConsent::query()->create([
            'consent_number' => $this->numbers->next('BK_CONSENT', 'BK-CS'),
            'student_id' => $studentId,
            'case_id' => $data['case_id'] ?? null,
            'referral_id' => $data['referral_id'] ?? null,
            'guardian_id' => $data['guardian_id'] ?? null,
            'requested_by' => $actor->id,
            'scope' => $data['scope'] ?? 'COUNSELING',
            'purpose' => $data['purpose'] ?? null,
            'status' => 'PENDING',
            'requested_at' => now(),
            'expires_at' => $data['expires_at'] ?? now()->addDays(14)->endOfDay(),
        ]);

        $this->audit->record(AuditAction::CREATE, $consent, $actor, ['student_id' => $studentId]);

        $this->notifications->notifyGuardians(
            $studentId,
            NotificationEvent::CONSENT_REQUIRED,
            'Sekolah memerlukan persetujuan Anda untuk layanan BK ('.$consent->scope.').',
            ['consent_id' => $consent->id, 'case_id' => $consent->case_id],
        );

        return $consent->refresh();
    }

    public function decide(User $actor, BkConsent $consent, string $decision, ?string $note = null): BkConsent
    {
        if ($consent->status !== 'PENDING') {
            throw new BkValidationException(['status' => ['Persetujuan ini sudah diputuskan.']]);
        }

        if (! $this->access->isGuardianOf($actor, (int) $consent->student_id) && ! $this->access->isBkAdmin($actor)) {
            throw new BkValidationException(['consent' => ['Hanya wali sah dari siswa ini yang dapat memutuskan.']]);
        }

        $decision = strtoupper($decision);

        if (! in_array($decision, ['APPROVED', 'DECLINED'], true)) {
            throw new BkValidationException(['decision' => ['Keputusan harus APPROVED atau DECLINED.']]);
        }

        $consent->forceFill([
            'status' => $decision,
            'decided_at' => now(),
            'decided_by' => $actor->id,
            'decision_note' => $note,
        ])->save();

        $this->audit->record(AuditAction::CONSENT_DECIDE, $consent->refresh(), $actor, [
            'reason' => $note,
            'changes' => ['status' => ['from' => 'PENDING', 'to' => $decision]],
        ]);

        if ($consent->case !== null) {
            app(BkTimelineService::class)->record(
                \App\Modules\Bk\Domain\Enums\CaseEventType::STATUS_CHANGED,
                $actor,
                $consent->case,
                summary: 'Persetujuan orang tua: '.($decision === 'APPROVED' ? 'disetujui' : 'ditolak').'.',
                related: $consent,
            );
        }

        return $consent->refresh();
    }

    /** Tutup persetujuan yang kedaluwarsa (perintah terjadwal). */
    public function expireStale(): int
    {
        return BkConsent::query()->expirable()->update(['status' => 'EXPIRED']);
    }

    public function hasApproval(int $studentId, string $scope = 'COUNSELING'): bool
    {
        return BkConsent::query()
            ->where('student_id', $studentId)
            ->where('scope', $scope)
            ->where('status', 'APPROVED')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->exists();
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = BkConsent::query()->with(['student:id,full_name,nis', 'case:id,case_number']);

        if (! $this->access->isBkStaff($actor)) {
            $guardianIds = $this->access->guardianStudentIds($actor);
            $query->whereIn('student_id', $guardianIds === [] ? [-1] : $guardianIds);
        }

        return $query
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(filter_var($filters['pending'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->pending())
            ->when($filters['student_id'] ?? null, fn ($q, $id) => $q->where('student_id', $id))
            ->orderByDesc('requested_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Persetujuan yang menunggu keputusan wali (dashboard sekolah). */
    public function pendingForCase(BkCase $case): array
    {
        return $case->consents()->pending()->get()->all();
    }
}
