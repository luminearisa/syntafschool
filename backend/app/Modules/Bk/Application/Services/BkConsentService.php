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
            'status' => \App\Modules\Bk\Domain\Enums\ConsentStatus::REQUESTED->value,
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
        if (! in_array($consent->status, ['REQUESTED', 'PENDING'], true)) {
            // Menerima istilah kanonik (REQUESTED) maupun ejaan Phase 1 (PENDING),
            // sehingga alur lama tetap berjalan setelah penyelarasan istilah.
            if (! in_array($consent->status, ['REQUESTED', 'PENDING'], true)) {
                throw new BkValidationException(['status' => ['Persetujuan ini sudah diputuskan.']]);
            }
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
            ->whereIn('status', ['GRANTED', 'APPROVED'])
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
            ->when($filters['case_id'] ?? null, fn ($q, $id) => $q->where('case_id', $id))
            ->when($filters['scope'] ?? null, fn ($q, $scope) => $q->where('scope', $scope))
            // Persetujuan yang akan berakhir: pertanyaan rutin menjelang akhir tahun ajaran.
            ->when($filters['expiring_days'] ?? null, fn ($q, $days) => $q
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '<=', now()->addDays((int) $days))
                ->whereDate('expires_at', '>=', today()))
            ->orderByDesc('requested_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Persetujuan yang menunggu keputusan wali (dashboard sekolah). */
    public function pendingForCase(BkCase $case): array
    {
        return $case->consents()->pending()->get()->all();
    }

    // =====================================================================
    // Phase 2 — persetujuan granular
    // =====================================================================

    /**
     * Cakupan persetujuan yang dikenal modul.
     *
     * Izin bersifat per-cakupan: persetujuan konseling tidak berarti izin berbagi
     * dokumen, dan izin mengikuti program tidak berarti izin merujuk ke pihak luar.
     * Karena itu setiap permintaan menyebut cakupannya secara eksplisit.
     *
     * @return list<array{value: string, label: string}>
     */
    public function scopes(): array
    {
        return \App\Modules\Bk\Domain\Enums\ConsentScope::options();
    }

    /**
     * Mencatat pemberian izin oleh wali (atau oleh pihak berwenang bila cakupannya
     * memang tidak menuntut keputusan wali).
     *
     * @param array<string, mixed> $data
     */
    public function grant(User $actor, BkConsent $consent, array $data = []): BkConsent
    {
        if ($consent->status === \App\Modules\Bk\Domain\Enums\ConsentStatus::REVOKED) {
            throw new BkValidationException(['status' => ['Persetujuan yang sudah dicabut tidak dapat diaktifkan kembali; buat permintaan baru.']]);
        }

        $consent->forceFill([
            'status' => \App\Modules\Bk\Domain\Enums\ConsentStatus::GRANTED,
            'granted_by' => $actor->id,
            'granted_at' => now(),
            'decided_by' => $consent->decided_by ?? $actor->id,
            'decided_at' => $consent->decided_at ?? now(),
            'decision_note' => $data['note'] ?? $consent->decision_note,
            'expires_at' => $data['expires_at'] ?? $consent->expires_at ?? now()->addDays(
                (\App\Modules\Bk\Domain\Enums\ConsentScope::tryFrom((string) $consent->scope)?->defaultValidityDays()) ?? 180
            ),
            'document_id' => $data['document_id'] ?? $consent->document_id,
            'is_standing' => (bool) ($data['is_standing'] ?? $consent->is_standing),
        ])->save();

        $this->audit->record(\App\Modules\Bk\Domain\Enums\AuditAction::CONSENT_GRANT, $consent, $actor, [
            'scope' => $consent->scope,
            'expires_at' => $consent->expires_at?->toDateString(),
        ]);

        return $consent->refresh();
    }

    /**
     * Mencabut izin.
     *
     * Pencabutan tidak menghapus jejak pemberian sebelumnya: kolom `granted_at`
     * tetap terisi, sehingga pertanyaan "apakah pernah ada izin?" tetap terjawab.
     */
    public function revoke(User $actor, BkConsent $consent, string $reason, ?string $documentId = null): BkConsent
    {
        if ($consent->status === \App\Modules\Bk\Domain\Enums\ConsentStatus::REVOKED) {
            return $consent;
        }

        $consent->forceFill([
            'status' => \App\Modules\Bk\Domain\Enums\ConsentStatus::REVOKED,
            'revoked_by' => $actor->id,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ])->save();

        $this->audit->record(\App\Modules\Bk\Domain\Enums\AuditAction::CONSENT_REVOKE, $consent, $actor, [
            'scope' => $consent->scope,
            'reason' => $reason,
            'had_document' => $documentId !== null,
        ]);

        return $consent->refresh();
    }

    /** Persetujuan yang berlaku untuk cakupan tertentu (dipakai penjaga alur rujukan). */
    public function validFor(int $studentId, \App\Modules\Bk\Domain\Enums\ConsentScope $scope, ?int $caseId = null): ?BkConsent
    {
        return BkConsent::query()
            ->where('student_id', $studentId)
            ->where('scope', $scope->value)
            ->whereIn('status', [
                \App\Modules\Bk\Domain\Enums\ConsentStatus::GRANTED->value,
                \App\Modules\Bk\Domain\Enums\ConsentStatus::APPROVED->value,
            ])
            ->when($caseId !== null, fn ($q) => $q->where(fn ($inner) => $inner->whereNull('case_id')->orWhere('case_id', $caseId)))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('granted_at')
            ->first();
    }

    /** Persetujuan yang akan berakhir dalam N hari — dipakai pengingat otomatis. */
    public function expiring(int $days = 14): \Illuminate\Database\Eloquent\Collection
    {
        return BkConsent::query()
            ->whereIn('status', [
                \App\Modules\Bk\Domain\Enums\ConsentStatus::GRANTED->value,
                \App\Modules\Bk\Domain\Enums\ConsentStatus::APPROVED->value,
            ])
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays($days))
            ->whereDate('expires_at', '>=', today())
            ->with(['student:id,full_name'])
            ->get();
    }
}
