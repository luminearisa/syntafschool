<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\ConsentScope;
use App\Modules\Bk\Domain\Enums\ConsentStatus;
use App\Modules\Bk\Domain\Enums\ExternalReferralStatus;
use App\Modules\Bk\Domain\Enums\FollowUpType;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\Priority;
use App\Modules\Bk\Domain\Enums\ReferralProviderType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkConsent;
use App\Modules\Bk\Models\BkExternalReferral;
use App\Modules\Bk\Models\BkReferralProvider;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Rujukan ke pihak luar.
 *
 * Dua aturan yang tidak bisa dilanggar dari sini:
 *
 *  1. **Tidak ada data siswa yang keluar tanpa persetujuan.** Perpindahan status
 *     ke arah "dikirim ke pihak luar" ditolak selama persetujuan wali belum ada
 *     dan belum berlaku. Ini ditegakkan di layanan, bukan di tampilan.
 *
 *  2. **Rekam medis bukan milik modul ini.** Hasil dari pihak luar disimpan
 *     sebagai ringkasan naratif yang boleh dibagikan. Kolom `clinic_reference`
 *     hanya menunjuk rekaman di Modul Clinic/Health bila ada; BK tidak pernah
 *     menyalin diagnosis, resep, atau riwayat klinis.
 */
class BkExternalReferralService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
        private readonly BkConsentService $consents,
        private readonly BkFollowUpService $followUps,
    ) {
    }

    // ------------------------------------------------------------------ penyedia

    /** @param array<string, mixed> $filters */
    public function providers(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return BkReferralProvider::query()
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('provider_type', $type))
            ->when($filters['active'] ?? null, fn ($q) => $q->active())
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->orderBy('name')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function createProvider(User $actor, array $data): BkReferralProvider
    {
        $type = ReferralProviderType::from((string) $data['provider_type']);

        $provider = BkReferralProvider::query()->create([
            'name' => $data['name'],
            'provider_type' => $type,
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'is_external' => (bool) ($data['is_external'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'requires_consent' => (bool) ($data['requires_consent'] ?? $type->requiresConsent()),
            'confidentiality_level' => $type->defaultConfidentiality(),
            'service_scope' => $data['service_scope'] ?? null,
            'sla_response_days' => isset($data['sla_response_days']) ? (string) $data['sla_response_days'] : null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit->record(AuditAction::CREATE, $provider, $actor, ['provider_type' => $type->value]);

        return $provider;
    }

    /** @param array<string, mixed> $data */
    public function updateProvider(User $actor, BkReferralProvider $provider, array $data): BkReferralProvider
    {
        $before = $provider->only(['name', 'provider_type', 'phone', 'email', 'is_active', 'requires_consent']);

        $provider->fill(array_filter([
            'name' => $data['name'] ?? null,
            'provider_type' => $data['provider_type'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'is_active' => $data['is_active'] ?? null,
            'requires_consent' => $data['requires_consent'] ?? null,
            'notes' => $data['notes'] ?? null,
            'updated_by' => $actor->id,
        ], fn ($value): bool => $value !== null))->save();

        $this->audit->record(AuditAction::UPDATE, $provider, $actor, [
            'changed' => array_keys($this->audit->diff($before, $provider->only(array_keys($before)))),
        ]);

        return $provider->refresh();
    }

    // ------------------------------------------------------------------ rujukan

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = BkExternalReferral::query()
            ->with(['student:id,full_name,nis', 'provider:id,name,provider_type', 'case:id,case_number', 'consent:id,status,scope,expires_at']);

        $this->scopeFor($query, $actor);

        return $query
            ->when($filters['student_id'] ?? null, fn ($q, $id) => $q->where('student_id', (int) $id))
            ->when($filters['case_id'] ?? null, fn ($q, $id) => $q->where('case_id', (int) $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'open' ? $q->open() : $q->where('status', $status))
            ->when($filters['provider_id'] ?? null, fn ($q, $id) => $q->where('referral_provider_id', (int) $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): BkExternalReferral
    {
        $studentId = (int) $data['student_id'];
        $case = isset($data['case_id']) ? BkCase::query()->find((int) $data['case_id']) : null;

        if ($case !== null) {
            $this->access->assertCanViewCase($actor, $case, 'Anda tidak memiliki akses ke kasus rujukan ini.');
        }

        $provider = isset($data['referral_provider_id'])
            ? BkReferralProvider::query()->find((int) $data['referral_provider_id'])
            : null;

        $referral = BkExternalReferral::query()->create([
            'referral_number' => $this->numbers->next('BK_EXTERNAL_REFERRAL', 'BK-EX'),
            'case_id' => $case?->id,
            'student_id' => $studentId,
            'referral_provider_id' => $provider?->id,
            'status' => ExternalReferralStatus::DRAFT,
            'urgency' => Priority::tryFrom((string) ($data['urgency'] ?? 'NORMAL')) ?? Priority::NORMAL,
            'reason' => $data['reason'] ?? null,
            'assessment' => $data['assessment'] ?? null,
            'referral_date' => $data['referral_date'] ?? null,
            'confidentiality_level' => $provider?->confidentiality_level ?? \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::CONFIDENTIAL,
            'referred_by' => $actor->id,
            'owner_user_id' => $data['owner_user_id'] ?? $actor->id,
            'created_by' => $actor->id,
        ]);

        $this->audit->record(AuditAction::EXTERNAL_REFERRAL_CREATE, $referral, $actor, [
            'student_id' => $studentId,
            'provider_id' => $provider?->id,
            'case_id' => $case?->id,
        ]);

        return $referral->refresh();
    }

    /** Mencatat asesmen internal sebelum merujuk. */
    public function recordAssessment(User $actor, BkExternalReferral $referral, string $assessment): BkExternalReferral
    {
        $this->assertCanManage($actor, $referral);

        $referral->forceFill([
            'assessment' => $assessment,
            'status' => $this->advance($referral->status, ExternalReferralStatus::ASSESSMENT),
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $referral, $actor, ['stage' => 'ASSESSMENT']);

        return $referral->refresh();
    }

    /**
     * Meminta persetujuan wali sebelum data dibagikan ke pihak luar.
     *
     * Yang dibuat adalah permintaan berstatus REQUESTED — bukan izin. Izin hanya
     * lahir dari keputusan wali, dan keputusan itulah yang membuka jalan ke tahap
     * berikutnya.
     */
    public function requestConsent(User $actor, BkExternalReferral $referral, ?string $purpose = null): BkExternalReferral
    {
        $this->assertCanManage($actor, $referral);

        if ($referral->consent_id !== null && $referral->consent?->status->isGranted()) {
            return $referral;
        }

        $consent = $this->consents->request($actor, $referral->student_id, [
            'scope' => ConsentScope::EXTERNAL_REFERRAL->value,
            'case_id' => $referral->case_id,
            'purpose' => $purpose ?? 'Rujukan kepada '.($referral->provider?->name ?? 'pihak luar').'.',
            'expires_at' => now()->addDays(ConsentScope::EXTERNAL_REFERRAL->defaultValidityDays())->toDateString(),
        ]);

        $referral->forceFill([
            'consent_id' => $consent->id,
            'status' => $this->advance($referral->status, ExternalReferralStatus::PENDING_CONSENT),
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $referral, $actor, ['stage' => 'PENDING_CONSENT', 'consent_id' => $consent->id]);

        return $referral->refresh()->load('consent');
    }

    /** Mengirim rujukan ke pihak luar. Persetujuan wajib sudah berlaku. */
    public function submit(User $actor, BkExternalReferral $referral, array $data = []): BkExternalReferral
    {
        $this->assertCanManage($actor, $referral);

        if (! $this->hasValidConsent($referral)) {
            throw new BkValidationException([
                'consent_id' => ['Rujukan tidak dapat dikirim sebelum persetujuan wali berlaku.'],
            ]);
        }

        $referral->forceFill([
            'status' => $this->advance($referral->status, ExternalReferralStatus::REFERRED),
            'referred_at' => now(),
            'referral_date' => $data['referral_date'] ?? $referral->referral_date ?? now()->toDateString(),
            'referral_document_id' => $data['referral_document_id'] ?? $referral->referral_document_id,
            'consent_obtained_at' => $referral->consent?->granted_at ?? $referral->consent_obtained_at,
            'updated_by' => $actor->id,
        ])->save();

        $this->notifyGuardians($referral, 'Rujukan kepada pihak luar telah dikirim.');

        $this->audit->record(AuditAction::UPDATE, $referral, $actor, ['stage' => 'REFERRED']);

        return $referral->refresh();
    }

    public function scheduleAppointment(User $actor, BkExternalReferral $referral, array $data): BkExternalReferral
    {
        $this->assertCanManage($actor, $referral);

        $referral->forceFill([
            'appointment_at' => $data['appointment_at'],
            'appointment_location' => $data['appointment_location'] ?? null,
            'status' => $this->advance($referral->status, ExternalReferralStatus::APPOINTMENT_SCHEDULED),
            'updated_by' => $actor->id,
        ])->save();

        $this->notifyGuardians($referral, 'Jadwal pertemuan dengan pihak luar telah ditetapkan.');

        $this->audit->record(AuditAction::UPDATE, $referral, $actor, ['stage' => 'APPOINTMENT_SCHEDULED']);

        return $referral->refresh();
    }

    /**
     * Mencatat hasil dari pihak luar.
     *
     * Medan klinis ditolak dengan penjelasan, bukan diabaikan diam-diam: guru/konselor
     * yang mencoba menempelkan diagnosis akan diberi tahu bahwa rekam medis adalah
     * milik Modul Clinic/Health.
     */
    public function recordResult(User $actor, BkExternalReferral $referral, array $data): BkExternalReferral
    {
        $this->assertCanManage($actor, $referral);

        $forbidden = array_intersect(array_keys($data), [
            'diagnosis', 'icd_code', 'prescription', 'medication', 'medical_record', 'lab_result',
        ]);

        if ($forbidden !== []) {
            throw new BkValidationException([
                'outcome' => [
                    'Modul BK tidak menyimpan data medis ('.implode(', ', $forbidden).'). '
                    .'Rekam medis adalah milik Modul Clinic/Health; simpan ringkasan yang boleh dibagikan, '
                    .'dan cantumkan nomor rujukannya pada kolom clinic_reference bila diperlukan.',
                ],
            ]);
        }

        $referral->forceFill([
            'outcome' => $data['outcome'] ?? $referral->outcome,
            'external_summary' => $data['external_summary'] ?? $referral->external_summary,
            'clinic_reference' => $data['clinic_reference'] ?? $referral->clinic_reference,
            'result_document_id' => $data['result_document_id'] ?? $referral->result_document_id,
            'result_received_at' => now(),
            'outcome_shared_with_parent' => (bool) ($data['outcome_shared_with_parent'] ?? false),
            'status' => $this->advance($referral->status, ExternalReferralStatus::RESULT_RECEIVED),
            'updated_by' => $actor->id,
        ])->save();

        if ($referral->outcome_shared_with_parent) {
            $this->notifyGuardians($referral, 'Ringkasan hasil rujukan telah tersedia.');
        }

        $this->audit->record(AuditAction::UPDATE, $referral, $actor, [
            'stage' => 'RESULT_RECEIVED',
            'has_outcome' => ! empty($data['outcome']),
            'shared_with_parent' => $referral->outcome_shared_with_parent,
        ]);

        return $referral->refresh();
    }

    /** Membuat tindak lanjut internal setelah hasil diterima. */
    public function createFollowUp(User $actor, BkExternalReferral $referral, array $data = []): BkExternalReferral
    {
        $this->assertCanManage($actor, $referral);

        if ($referral->case === null) {
            throw new BkValidationException([
                'case_id' => ['Tindak lanjut rujukan memerlukan kasus yang menaunginya.'],
            ]);
        }

        $followUp = $this->followUps->create($actor, $referral->case, [
            'title' => $data['title'] ?? 'Tindak lanjut hasil rujukan '.$referral->referral_number,
            'type' => FollowUpType::PROGRESS_REVIEW->value,
            'description' => $data['description'] ?? $referral->external_summary,
            'assigned_to' => $data['assigned_to'] ?? $referral->owner_user_id,
            'due_date' => $data['due_date'] ?? now()->addDays(14)->toDateString(),
        ]);

        $referral->forceFill([
            'follow_up_id' => $followUp->id,
            'follow_up_plan' => $data['description'] ?? $referral->follow_up_plan,
            'status' => $this->advance($referral->status, ExternalReferralStatus::FOLLOW_UP),
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $referral, $actor, ['stage' => 'FOLLOW_UP', 'follow_up_id' => $followUp->id]);

        return $referral->refresh();
    }

    public function complete(User $actor, BkExternalReferral $referral, ?array $data = null): BkExternalReferral
    {
        $this->assertCanManage($actor, $referral);

        $referral->forceFill([
            'status' => ExternalReferralStatus::COMPLETED,
            'closed_at' => now(),
            'follow_up_plan' => $data['follow_up_plan'] ?? $referral->follow_up_plan,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $referral, $actor, ['stage' => 'COMPLETED']);

        return $referral->refresh();
    }

    public function cancel(User $actor, BkExternalReferral $referral, string $reason): BkExternalReferral
    {
        $this->assertCanManage($actor, $referral);

        $referral->forceFill([
            'status' => ExternalReferralStatus::CANCELLED,
            'closed_at' => now(),
            'outcome' => trim(($referral->outcome ?? '').' | Dibatalkan: '.$reason),
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $referral, $actor, ['cancelled' => true, 'reason' => $reason]);

        return $referral->refresh();
    }

    // ------------------------------------------------------------------ aturan

    /** Status rujukan boleh melangkah ke tahap tertentu? */
    public function advance(ExternalReferralStatus $current, ExternalReferralStatus $target): ExternalReferralStatus
    {
        if ($current->isTerminal()) {
            throw new BkValidationException([
                'status' => ["Rujukan yang sudah {$current->label()} tidak dapat dilanjutkan."],
            ]);
        }

        return $target;
    }

    /** Persetujuan ada, berlaku, dan berstatus diberikan? */
    public function hasValidConsent(BkExternalReferral $referral): bool
    {
        $consent = $referral->consent;

        if ($consent === null) {
            return false;
        }

        if (! in_array($consent->status, [ConsentStatus::GRANTED->value, ConsentStatus::APPROVED->value], true)) {
            return false;
        }

        return $consent->expires_at === null || $consent->expires_at->isFuture();
    }

    public function canView(User $actor, BkExternalReferral $referral): bool
    {
        if ($this->access->isBkStaff($actor)) {
            return true;
        }

        if ($this->access->isGuardianOf($actor, $referral->student_id)) {
            // Wali hanya melihat bila hasilnya memang disiapkan untuk dibagikan.
            return $referral->outcome_shared_with_parent;
        }

        if ($actor->id === $referral->owner_user_id || $actor->id === $referral->referred_by) {
            return true;
        }

        if ($referral->case !== null && $this->access->canViewCounselingContent($actor, $referral->case)) {
            return true;
        }

        return false;
    }

    public function assertCanView(User $actor, BkExternalReferral $referral): void
    {
        if (! $this->canView($actor, $referral)) {
            $this->audit->logAccess($actor, 'VIEW_CONFIDENTIAL', $referral, false, 'Rujukan eksternal di luar cakupan.');

            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Anda tidak memiliki akses ke rujukan ini.');
        }

        $this->audit->record(AuditAction::EXTERNAL_REFERRAL_VIEW, $referral, $actor, ['granted' => true]);
    }

    public function assertCanManage(User $actor, BkExternalReferral $referral): void
    {
        if ($this->access->isBkStaff($actor)) {
            return;
        }

        if ($actor->id === $referral->owner_user_id || $actor->id === $referral->referred_by) {
            return;
        }

        throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Hanya tim BK yang dapat mengubah rujukan ini.');
    }

    /** Cakupan baris: tim BK seluruhnya, wali hanya anaknya, siswa hanya dirinya. */
    protected function scopeFor($query, User $actor): void
    {
        if ($this->access->isBkStaff($actor)) {
            return;
        }

        $studentId = $this->access->studentIdForUser($actor);
        $guardianIds = $this->access->guardianStudentIds($actor);

        $query->where(function ($inner) use ($actor, $studentId, $guardianIds): void {
            $inner->where('owner_user_id', $actor->id)
                ->orWhere('referred_by', $actor->id);

            if ($studentId !== null) {
                $inner->orWhere('student_id', $studentId);
            }

            if ($guardianIds !== []) {
                $inner->orWhere(fn ($guardian) => $guardian
                    ->whereIn('student_id', $guardianIds)
                    ->where('outcome_shared_with_parent', true));
            }
        });
    }

    protected function notifyGuardians(BkExternalReferral $referral, string $summary): void
    {
        $this->notifications->notifyGuardians(
            $referral->student_id,
            NotificationEvent::EXTERNAL_REFERRAL_UPDATED,
            $summary,
            [
                'referral_number' => $referral->referral_number,
                'status' => $referral->status->value,
                'note' => 'Ringkasan rujukan; isi rekam medis tidak dikirim melalui modul ini.',
            ],
        );
    }
}
