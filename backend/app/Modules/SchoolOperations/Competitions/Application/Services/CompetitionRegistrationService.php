<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Application\Services;

use App\Models\Student;
use App\Models\User;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\FinanceProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\ParentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\StudentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Jobs\SendCompetitionNotificationJob;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionConsent;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionDocumentRequirement;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEligibilityEvaluation;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionNotification;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionParticipant;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRegistration;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRegistrationDocument;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRegistrationForm;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRegistrationStatusHistory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRegistrationValue;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionTeam;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionTeamMember;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionWaitlist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/** Registration, eligibility, document/consent gates, waitlist and participant creation. */
class CompetitionRegistrationService
{
    public function __construct(
        private readonly CompetitionEligibilityEngine $eligibility,
        private readonly CompetitionAuditService $audit,
        private readonly CompetitionScopeService $scope,
        private readonly StudentProviderInterface $students,
        private readonly ParentProviderInterface $parents,
        private readonly FinanceProviderInterface $finance,
    ) {}

    public function paginate(CompetitionEdition $edition, array $filters, User $actor)
    {
        $this->scope->assertEdition($actor, $edition);

        return CompetitionRegistration::query()
            ->where('edition_id', $edition->id)
            ->when(! empty($filters['category_id']), fn (Builder $query) => $query->where('category_id', $filters['category_id']))
            ->when(! empty($filters['status']), fn (Builder $query) => $query->where('status', strtoupper((string) $filters['status'])))
            ->when(! empty($filters['search']), function (Builder $query) use ($filters): void {
                $term = '%'.trim((string) $filters['search']).'%';
                $query->where(function (Builder $search) use ($term): void {
                    $search->where('registration_number', 'like', $term)
                        ->orWhere('external_participant_name', 'like', $term)
                        ->orWhere('institution_name', 'like', $term);
                });
            })
            ->orderByRaw("CASE status WHEN 'SUBMITTED' THEN 1 WHEN 'UNDER_REVIEW' THEN 2 WHEN 'NEEDS_REVISION' THEN 3 ELSE 4 END")
            ->orderByDesc('submitted_at')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
    }

    public function mine(User $actor, array $filters = [])
    {
        $studentIds = $this->parents->studentIdsForUser((int) $actor->id);
        if ($actor->userable instanceof Student) {
            $studentIds[] = (int) $actor->userable_id;
        }
        $studentIds = array_values(array_unique(array_filter($studentIds)));

        return CompetitionRegistration::query()
            ->where(function (Builder $query) use ($actor, $studentIds): void {
                $query->where('submitted_by_user_id', $actor->id);
                if ($studentIds !== []) {
                    $query->orWhereIn('student_id', $studentIds);
                }
            })
            ->when(! empty($filters['status']), fn (Builder $query) => $query->where('status', strtoupper((string) $filters['status'])))
            ->orderByDesc('created_at')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
    }

    public function show(CompetitionRegistration $registration, User $actor): array
    {
        $this->assertOwnerOrManager($registration, $actor);

        return [
            'registration' => $registration->toArray(),
            'values' => CompetitionRegistrationValue::query()->where('registration_id', $registration->id)->get(),
            'documents' => CompetitionRegistrationDocument::query()->where('registration_id', $registration->id)->get(),
            'consents' => CompetitionConsent::query()->where('registration_id', $registration->id)->get(),
            'evaluations' => CompetitionEligibilityEvaluation::query()->where('registration_id', $registration->id)->with('competitionEligibilityRuleResults')->orderByDesc('id')->get(),
            'history' => CompetitionRegistrationStatusHistory::query()->where('registration_id', $registration->id)->orderBy('id')->get(),
        ];
    }

    public function create(CompetitionEdition $edition, array $data, User $actor): CompetitionRegistration
    {
        $this->scope->assertEdition($actor, $edition);
        $category = CompetitionCategory::query()->where('edition_id', $edition->id)->where('is_active', true)->findOrFail($data['category_id']);
        $this->assertRegistrationOpen($edition);

        $participantType = strtoupper((string) ($data['participant_type'] ?? ($category->participant_mode === 'TEAM' ? 'TEAM' : 'STUDENT')));
        if ($category->participant_mode === 'TEAM' && $participantType !== 'TEAM') {
            throw ValidationException::withMessages(['participant_type' => 'Kategori ini hanya menerima pendaftaran beregu.']);
        }
        if ($category->participant_mode === 'INDIVIDUAL' && $participantType === 'TEAM') {
            throw ValidationException::withMessages(['participant_type' => 'Kategori ini hanya menerima peserta individu.']);
        }
        if (! empty($data['form_id']) && ! CompetitionRegistrationForm::query()->where('edition_id', $edition->id)->where('is_active', true)->whereKey($data['form_id'])->exists()) {
            throw ValidationException::withMessages(['form_id' => 'Form pendaftaran tidak aktif atau bukan milik edition ini.']);
        }
        $studentId = isset($data['student_id']) ? (int) $data['student_id'] : null;
        $teamId = isset($data['team_id']) ? (int) $data['team_id'] : null;
        $this->assertRegistrationAuthority($actor, $participantType, $studentId, $data);
        $this->assertParticipantTypeAllowed($edition, $participantType);

        $studentSnapshot = null;
        if ($participantType === 'STUDENT') {
            if ($studentId === null) {
                throw ValidationException::withMessages(['student_id' => 'Pendaftaran siswa memerlukan student_id.']);
            }
            $studentSnapshot = $this->students->snapshot($studentId);
            if ($studentSnapshot === null || ! (bool) ($studentSnapshot['active'] ?? $studentSnapshot['is_active'] ?? true)) {
                throw ValidationException::withMessages(['student_id' => 'Siswa tidak ditemukan atau tidak aktif.']);
            }
        }

        if ($participantType === 'TEAM') {
            if ($teamId === null) {
                throw ValidationException::withMessages(['team_id' => 'Pendaftaran beregu memerlukan tim yang sudah dibuat.']);
            }
            $team = CompetitionTeam::query()->where('edition_id', $edition->id)->where('category_id', $category->id)->findOrFail($teamId);
            if ($team->status === 'WITHDRAWN' || $team->status === 'DISQUALIFIED') {
                throw ValidationException::withMessages(['team_id' => 'Tim sudah tidak dapat mendaftar.']);
            }
        }

        if (! empty($data['idempotency_key'])) {
            $existing = CompetitionRegistration::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ((int) $existing->edition_id !== (int) $edition->id || (int) $existing->category_id !== (int) $category->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key sudah dipakai untuk operasi lain.']);
                }

                return $existing;
            }
        }

        $allowDuplicate = (bool) data_get($category->settings, 'allow_duplicate_participant', false);
        if (! $allowDuplicate && ($studentId !== null || $teamId !== null)) {
            $duplicate = CompetitionRegistration::query()->where('edition_id', $edition->id)->where('category_id', $category->id)
                ->whereNotIn('status', ['CANCELLED', 'WITHDRAWN', 'REJECTED'])
                ->where(function (Builder $query) use ($studentId, $teamId): void {
                    if ($studentId !== null) {
                        $query->where('student_id', $studentId);
                    }
                    if ($teamId !== null) {
                        $query->orWhere('team_id', $teamId);
                    }
                })->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['participant' => 'Peserta/tim sudah terdaftar pada kategori ini.']);
            }
        }

        $fee = (float) ($category->registration_fee ?? 0);
        $registration = DB::transaction(function () use ($edition, $category, $data, $actor, $participantType, $studentId, $teamId, $fee, $studentSnapshot): CompetitionRegistration {
            CompetitionEdition::query()->whereKey($edition->id)->lockForUpdate()->firstOrFail();
            CompetitionCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

            if (! empty($data['idempotency_key'])) {
                $existing = CompetitionRegistration::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            if (! (bool) data_get($category->settings, 'allow_duplicate_participant', false) && ($studentId !== null || $teamId !== null)) {
                $duplicateQuery = CompetitionRegistration::query()->where('edition_id', $edition->id)->where('category_id', $category->id)
                    ->whereNotIn('status', ['CANCELLED', 'WITHDRAWN', 'REJECTED'])
                    ->where(function (Builder $query) use ($studentId, $teamId): void {
                        if ($studentId !== null) {
                            $query->where('student_id', $studentId);
                        }
                        if ($teamId !== null) {
                            $query->orWhere('team_id', $teamId);
                        }
                    });
                if ($duplicateQuery->exists()) {
                    throw ValidationException::withMessages(['participant' => 'Peserta/tim sudah terdaftar pada kategori ini.']);
                }
            }

            $row = CompetitionRegistration::query()->create([
                'edition_id' => $edition->id,
                'category_id' => $category->id,
                'form_id' => $data['form_id'] ?? null,
                'team_id' => $teamId,
                'registration_number' => $this->nextRegistrationNumber($edition),
                'participant_type' => $participantType,
                'student_id' => $studentId,
                'employee_id' => $data['employee_id'] ?? null,
                'submitted_by_user_id' => $actor->id,
                'institution_name' => $data['institution_name'] ?? $studentSnapshot['institution_name'] ?? null,
                'external_participant_name' => $data['external_participant_name'] ?? null,
                'external_participant_email' => $data['external_participant_email'] ?? null,
                'external_participant_phone' => $data['external_participant_phone'] ?? null,
                'external_participant_id_number' => $data['external_participant_id_number'] ?? null,
                'status' => 'DRAFT',
                'eligibility_status' => null,
                'document_status' => null,
                'consent_status' => null,
                'payment_status' => $fee > 0 ? 'UNPAID' : 'NOT_REQUIRED',
                'fee_amount' => $fee > 0 ? $fee : null,
                'currency' => $category->currency ?: 'IDR',
                'submitted_via' => strtoupper((string) ($data['submitted_via'] ?? 'WEB')),
                'source' => $data['source'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'metadata' => array_merge((array) ($data['metadata'] ?? []), ['participant_snapshot' => $studentSnapshot]),
            ]);
            $this->history($row, null, 'DRAFT', $actor, 'Registration draft dibuat.');
            $this->audit->record('RegistrationCreated', $actor, $row, $edition, after: ['id' => $row->id, 'category_id' => $category->id, 'participant_type' => $participantType]);

            return $row;
        });

        if ($fee > 0 && $this->finance->isAvailable()) {
            try {
                $charge = $this->finance->requestRegistrationCharge([
                    'idempotency_key' => 'competition-registration-'.$registration->id,
                    'registration_id' => $registration->id,
                    'student_id' => $registration->student_id,
                    'amount' => $fee,
                    'currency' => $registration->currency,
                    'description' => 'Competition '.$edition->name.' / '.$category->name,
                ]);
                $paymentStatus = strtoupper((string) ($charge['status'] ?? 'UNPAID'));
                if (in_array($paymentStatus, ['NOT_REQUIRED', 'UNPAID', 'PARTIAL', 'PAID', 'REFUNDED', 'WAIVED'], true)) {
                    $registration->forceFill(['payment_status' => $paymentStatus, 'finance_reference' => $charge['reference'] ?? null])->save();
                }
            } catch (Throwable $exception) {
                $this->audit->record('FinanceChargeRequestFailed', $actor, $registration, $edition, after: ['provider' => 'finance'], reason: $exception->getMessage());
            }
        }

        return $registration->refresh();
    }

    public function evaluate(CompetitionRegistration $registration, User $actor): array
    {
        $this->assertOwnerOrManager($registration, $actor);

        return $this->eligibility->evaluate($registration, $actor, true);
    }

    public function submit(CompetitionRegistration $registration, User $actor, array $data = []): CompetitionRegistration
    {
        return DB::transaction(function () use ($registration, $actor, $data): CompetitionRegistration {
            $registration = CompetitionRegistration::query()->whereKey($registration->id)->lockForUpdate()->firstOrFail();
            $edition = CompetitionEdition::query()->findOrFail($registration->edition_id);
            $this->assertRegistrationOpen($edition);
            $this->assertOwnerOrManager($registration, $actor);
            if (! in_array($registration->status, ['DRAFT', 'NEEDS_REVISION', 'UNDER_REVIEW', 'ELIGIBILITY_FAILED', 'DOCUMENT_FAILED'], true)) {
                throw ValidationException::withMessages(['registration' => 'Pendaftaran tidak dapat dikirim dari status '.$registration->status.'.']);
            }

            $evaluation = $this->eligibility->evaluate($registration, $actor, true);
            $category = CompetitionCategory::query()->findOrFail($registration->category_id);
            $requiresConsent = (bool) $edition->requires_parent_consent || (bool) $category->requires_parent_consent;
            $consentStatus = $registration->consent_status;
            if ($requiresConsent && $registration->student_id !== null) {
                $consent = CompetitionConsent::query()->where('registration_id', $registration->id)->where('consent_type', 'PARENT')->latest('id')->first();
                if ($consent === null) {
                    $guardianUsers = $this->parents->userIdsFor((int) $registration->student_id);
                    $consent = CompetitionConsent::query()->create([
                        'registration_id' => $registration->id,
                        'consent_type' => 'PARENT',
                        'status' => 'PENDING',
                        'requested_from_user_id' => $guardianUsers[0] ?? null,
                        'version' => 1,
                    ]);
                }
                $consentStatus = $consent->status;
                $this->queueNotification($edition, $registration, 'PARENT_CONSENT_REQUESTED', $this->parents->userIdsFor((int) $registration->student_id), ['registration_id' => $registration->id, 'consent_id' => $consent->id]);
            }

            $requiredDocuments = CompetitionDocumentRequirement::query()->where('category_id', $registration->category_id)->where('status', 'ACTIVE')->where('is_required', true)->get();
            $verifiedDocumentTypes = CompetitionRegistrationDocument::query()->where('registration_id', $registration->id)->where('status', 'VERIFIED')->pluck('document_type')->all();
            $missingDocuments = $requiredDocuments->filter(fn ($requirement) => ! in_array($requirement->document_type, $verifiedDocumentTypes, true));
            $documentStatus = $requiredDocuments->isEmpty() ? 'NOT_REQUIRED' : ($missingDocuments->isEmpty() ? 'VERIFIED' : 'PENDING');

            $status = match ($evaluation['result']) {
                'NOT_ELIGIBLE' => 'ELIGIBILITY_FAILED',
                'NEEDS_REVIEW' => 'UNDER_REVIEW',
                default => (($requiresConsent && $consentStatus !== 'APPROVED') || $documentStatus === 'PENDING') ? 'UNDER_REVIEW' : 'SUBMITTED',
            };
            if ($registration->status === 'NEEDS_REVISION' && $status === 'SUBMITTED') {
                $status = 'UNDER_REVIEW';
            }

            $from = (string) $registration->status;
            $registration->forceFill([
                'status' => $status,
                'submitted_at' => now(),
                'eligibility_status' => $evaluation['result'],
                'document_status' => $documentStatus,
                'consent_status' => $consentStatus,
                'metadata' => array_merge((array) $registration->metadata, ['submitted_payload' => $data ?: null]),
            ])->save();
            $this->history($registration, $from, $status, $actor, 'Pendaftaran dikirim.');
            $this->audit->record('RegistrationSubmitted', $actor, $registration, $edition, before: ['status' => $from], after: ['status' => $status, 'eligibility' => $evaluation['result'], 'documents' => $documentStatus, 'consent' => $consentStatus]);

            return $registration->refresh();
        });
    }

    public function decide(CompetitionRegistration $registration, User $actor, string $decision, ?string $reason = null, bool $override = false): CompetitionRegistration
    {
        $decision = strtoupper($decision);
        if (! in_array($decision, ['APPROVED', 'REJECTED', 'NEEDS_REVISION'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan harus APPROVED, REJECTED, atau NEEDS_REVISION.']);
        }
        $this->assertCanReview($registration, $actor);
        if ($decision === 'APPROVED') {
            $actor->can('registration.approve') || abort(403);
        } elseif ($decision === 'REJECTED') {
            $actor->can('registration.reject') || abort(403);
        }
        if ($override && (! $actor->can('registration.override') || trim((string) $reason) === '')) {
            abort(403, 'Override eligibility membutuhkan permission dan alasan audit.');
        }
        if ($decision === 'REJECTED' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($registration, $actor, $decision, $reason, $override): CompetitionRegistration {
            $registration = CompetitionRegistration::query()->whereKey($registration->id)->lockForUpdate()->firstOrFail();
            $edition = CompetitionEdition::query()->findOrFail($registration->edition_id);
            $category = CompetitionCategory::query()->where('edition_id', $edition->id)->findOrFail($registration->category_id);
            if (! in_array($registration->status, ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION', 'ELIGIBILITY_FAILED', 'DOCUMENT_FAILED', 'WAITLISTED'], true)) {
                throw ValidationException::withMessages(['registration' => 'Pendaftaran tidak dapat ditinjau dari status '.$registration->status.'.']);
            }
            $from = (string) $registration->status;
            if ($decision === 'REJECTED' || $decision === 'NEEDS_REVISION') {
                $status = $decision;
                $registration->forceFill([
                    'status' => $status,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                    'rejected_by' => $decision === 'REJECTED' ? $actor->id : null,
                    'rejected_at' => $decision === 'REJECTED' ? now() : null,
                    'rejection_reason' => $decision === 'REJECTED' ? $reason : null,
                    'revision_notes' => $decision === 'NEEDS_REVISION' ? $reason : null,
                ])->save();
                $this->history($registration, $from, $status, $actor, $reason);
                $this->audit->record('Registration'.$decision, $actor, $registration, $edition, before: ['status' => $from], after: ['status' => $status], reason: $reason);
                if ($decision === 'REJECTED') {
                    $this->queueNotification($edition, $registration, 'RegistrationRejected', $this->participantUserIds($registration), ['reason' => $reason]);
                }

                return $registration->refresh();
            }

            $latestEvaluation = CompetitionEligibilityEvaluation::query()
                ->where('registration_id', $registration->id)->latest('id')->first();
            if ($latestEvaluation === null) {
                $latest = $this->eligibility->evaluate($registration, $actor, true);
                $eligibilityResult = $latest['result'];
            } else {
                $eligibilityResult = (string) $latestEvaluation->result;
            }
            if ($eligibilityResult !== 'ELIGIBLE' && ! $override) {
                throw ValidationException::withMessages(['eligibility' => 'Pendaftaran belum eligible. Jalankan evaluasi atau gunakan override berizin dengan alasan.']);
            }
            $this->assertRequiredDocumentsVerified($registration);
            $this->assertConsentApproved($registration, $edition, $category);
            if (($edition->requires_payment || (float) ($registration->fee_amount ?? 0) > 0) && ! in_array($registration->payment_status, ['PAID', 'WAIVED'], true)) {
                throw ValidationException::withMessages(['payment_status' => 'Pembayaran pendaftaran belum lunas atau dibebaskan.']);
            }

            $categoryCapacity = $category->capacity;
            $capacity = $categoryCapacity ?? $edition->max_participants;
            if ($capacity !== null) {
                $approvedQuery = CompetitionRegistration::query()->where('edition_id', $edition->id)->where('status', 'APPROVED');
                if ($categoryCapacity !== null) {
                    $approvedQuery->where('category_id', $category->id);
                }
                $approvedCount = $approvedQuery->count();
                if ($approvedCount >= (int) $capacity) {
                    $position = (int) CompetitionWaitlist::query()->where('category_id', $category->id)->where('status', 'WAITING')->max('position') + 1;
                    CompetitionWaitlist::query()->firstOrCreate(
                        ['registration_id' => $registration->id],
                        ['category_id' => $category->id, 'position' => $position, 'priority' => (int) $registration->priority, 'status' => 'WAITING'],
                    );
                    $registration->forceFill(['status' => 'WAITLISTED', 'waitlist_position' => $position, 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
                    $this->history($registration, $from, 'WAITLISTED', $actor, 'Kuota kategori penuh.');
                    $this->audit->record('RegistrationWaitlisted', $actor, $registration, $edition, before: ['status' => $from], after: ['position' => $position]);

                    return $registration->refresh();
                }
            }

            $participant = $this->createParticipant($registration, $category, $actor, $override, $reason);
            $registration->forceFill([
                'status' => 'APPROVED',
                'eligibility_status' => $override && $eligibilityResult !== 'ELIGIBLE' ? 'OVERRIDE_APPROVED' : 'ELIGIBLE',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();
            $this->history($registration, $from, 'APPROVED', $actor, $reason);
            $this->audit->record('RegistrationApproved', $actor, $registration, $edition, before: ['status' => $from], after: ['status' => 'APPROVED', 'participant_id' => $participant->id, 'override' => $override], reason: $override ? $reason : null);
            $this->queueNotification($edition, $registration, 'RegistrationApproved', $this->participantUserIds($registration), ['participant_id' => $participant->id]);

            return $registration->refresh();
        });
    }

    public function syncPaymentStatus(CompetitionRegistration $registration, User $actor): CompetitionRegistration
    {
        if (! $actor->can('registration.review') && ! $actor->can('registration.approve')) {
            abort(403);
        }
        $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($registration->edition_id));
        if ($registration->finance_reference === null) {
            throw ValidationException::withMessages(['finance_reference' => 'Belum ada referensi pembayaran dari Finance.']);
        }
        if (! $this->finance->isAvailable()) {
            throw ValidationException::withMessages(['finance' => $this->finance->unavailableReason() ?: 'Finance provider tidak tersedia.']);
        }
        $payment = $this->finance->paymentStatus((string) $registration->finance_reference);
        if ($payment === null || ! in_array(strtoupper((string) ($payment['status'] ?? '')), ['NOT_REQUIRED', 'UNPAID', 'PARTIAL', 'PAID', 'REFUNDED', 'WAIVED'], true)) {
            throw ValidationException::withMessages(['payment_status' => 'Finance mengembalikan status pembayaran yang tidak dikenal.']);
        }
        $before = ['payment_status' => $registration->payment_status];
        $registration->forceFill(['payment_status' => strtoupper((string) $payment['status'])])->save();
        $this->audit->record('RegistrationPaymentStatusSynced', $actor, $registration, CompetitionEdition::query()->find($registration->edition_id), before: $before, after: ['payment_status' => $registration->payment_status, 'finance_reference' => $registration->finance_reference]);

        return $registration->refresh();
    }

    public function waivePayment(CompetitionRegistration $registration, User $actor, string $reason): CompetitionRegistration
    {
        if (! $actor->can('registration.override') || trim($reason) === '') {
            abort(403, 'Pembebasan biaya membutuhkan izin override dan alasan audit.');
        }
        $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($registration->edition_id));
        $before = ['payment_status' => $registration->payment_status];
        $registration->forceFill(['payment_status' => 'WAIVED'])->save();
        $this->audit->record('RegistrationFeeWaived', $actor, $registration, CompetitionEdition::query()->find($registration->edition_id), before: $before, after: ['payment_status' => 'WAIVED'], reason: $reason);

        return $registration->refresh();
    }

    public function decideConsent(CompetitionConsent $consent, User $actor, string $decision, ?string $note = null): CompetitionConsent
    {
        $decision = strtoupper($decision);
        if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            throw ValidationException::withMessages(['decision' => 'Persetujuan harus APPROVED atau REJECTED.']);
        }
        $registration = CompetitionRegistration::query()->findOrFail($consent->registration_id);
        if ($registration->student_id === null || ! $this->parents->canActFor((int) $actor->id, (int) $registration->student_id)) {
            abort(403, 'Persetujuan hanya dapat diberikan oleh wali yang terhubung pada siswa.');
        }

        return DB::transaction(function () use ($consent, $actor, $decision, $note, $registration): CompetitionConsent {
            $consent = CompetitionConsent::query()->whereKey($consent->id)->lockForUpdate()->firstOrFail();
            if ($consent->status !== 'PENDING') {
                throw ValidationException::withMessages(['consent' => 'Persetujuan sudah pernah diputuskan.']);
            }
            $consent->forceFill([
                'status' => $decision,
                'responded_by' => $actor->id,
                'responded_at' => now(),
                'response_note' => $note,
                'rejected_reason' => $decision === 'REJECTED' ? $note : null,
            ])->save();
            $registration->forceFill(['consent_status' => $decision])->save();
            $this->audit->record('ParentConsent'.$decision, $actor, $consent, CompetitionEdition::query()->find($registration->edition_id), after: ['status' => $decision], reason: $note);

            return $consent->refresh();
        });
    }

    public function uploadDocument(CompetitionRegistration $registration, UploadedFile $file, string $documentType, User $actor): CompetitionRegistrationDocument
    {
        $this->assertOwnerOrManager($registration, $actor);
        $type = strtoupper($documentType);
        $requirement = CompetitionDocumentRequirement::query()->where('category_id', $registration->category_id)->where('document_type', $type)->where('status', 'ACTIVE')->first();
        if ($requirement?->max_size_kb && $file->getSize() > $requirement->max_size_kb * 1024) {
            throw ValidationException::withMessages(['file' => 'Ukuran dokumen melampaui batas kategori.']);
        }
        if ($requirement?->allowed_mime_types) {
            $allowed = array_map('trim', explode(',', $requirement->allowed_mime_types));
            if (! in_array((string) $file->getMimeType(), $allowed, true)) {
                throw ValidationException::withMessages(['file' => 'Tipe MIME dokumen tidak diizinkan.']);
            }
        }
        $path = $file->store("competition/registrations/{$registration->id}", ['disk' => 'local']);
        $document = CompetitionRegistrationDocument::query()->create([
            'registration_id' => $registration->id,
            'requirement_id' => $requirement?->id,
            'document_type' => $type,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'checksum' => hash_file('sha256', Storage::disk('local')->path($path)),
            'status' => 'PENDING',
            'virus_scan_status' => 'PENDING',
            'version' => (int) CompetitionRegistrationDocument::query()->where('registration_id', $registration->id)->where('document_type', $type)->max('version') + 1,
        ]);
        $this->audit->record('RegistrationDocumentUploaded', $actor, $document, CompetitionEdition::query()->find($registration->edition_id), after: ['document_id' => $document->id, 'type' => $type]);

        return $document;
    }

    public function reviewDocument(CompetitionRegistrationDocument $document, User $actor, string $decision, ?string $reason = null): CompetitionRegistrationDocument
    {
        $actor->can('registration.review') || abort(403);
        $registration = CompetitionRegistration::query()->findOrFail($document->registration_id);
        $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($registration->edition_id));
        $decision = strtoupper($decision);
        if (! in_array($decision, ['VERIFIED', 'REJECTED'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan dokumen harus VERIFIED atau REJECTED.']);
        }
        $document->forceFill([
            'status' => $decision,
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'rejection_reason' => $decision === 'REJECTED' ? $reason : null,
        ])->save();
        $this->audit->record('RegistrationDocument'.$decision, $actor, $document, after: ['status' => $decision], reason: $reason);

        return $document->refresh();
    }

    public function withdraw(CompetitionRegistration $registration, User $actor, string $reason): CompetitionRegistration
    {
        $this->assertOwnerOrManager($registration, $actor);

        return DB::transaction(function () use ($registration, $actor, $reason): CompetitionRegistration {
            $registration = CompetitionRegistration::query()->whereKey($registration->id)->lockForUpdate()->firstOrFail();
            if (! in_array($registration->status, ['APPROVED', 'WAITLISTED', 'SUBMITTED', 'UNDER_REVIEW'], true)) {
                throw ValidationException::withMessages(['registration' => 'Pendaftaran tidak dapat ditarik pada status ini.']);
            }
            $from = (string) $registration->status;
            $wasApproved = $from === 'APPROVED';
            $registration->forceFill(['status' => 'WITHDRAWN', 'withdrawn_at' => now(), 'withdrawal_reason' => $reason])->save();
            if ($wasApproved) {
                CompetitionParticipant::query()->where('registration_id', $registration->id)->update(['status' => 'WITHDRAWN', 'is_active' => false, 'withdrawn_at' => now(), 'withdrawal_reason' => $reason]);
                $this->offerNextWaitlist((int) $registration->category_id, $actor);
            }
            $this->history($registration, $from, 'WITHDRAWN', $actor, $reason);
            $this->audit->record('ParticipantWithdrawn', $actor, $registration, CompetitionEdition::query()->find($registration->edition_id), before: ['status' => $from], after: ['status' => 'WITHDRAWN'], reason: $reason);

            return $registration->refresh();
        });
    }

    public function confirmWaitlist(CompetitionWaitlist $waitlist, User $actor): CompetitionRegistration
    {
        return DB::transaction(function () use ($waitlist, $actor): CompetitionRegistration {
            $waitlist = CompetitionWaitlist::query()->whereKey($waitlist->id)->lockForUpdate()->firstOrFail();
            $registration = CompetitionRegistration::query()->whereKey($waitlist->registration_id)->lockForUpdate()->firstOrFail();
            $this->assertOwnerOrManager($registration, $actor);
            if ($waitlist->status !== 'OFFERED' || ($waitlist->offer_expires_at && $waitlist->offer_expires_at->isPast())) {
                throw ValidationException::withMessages(['waitlist' => 'Tawaran waitlist tidak aktif atau sudah kedaluwarsa.']);
            }
            $edition = CompetitionEdition::query()->findOrFail($registration->edition_id);
            $category = CompetitionCategory::query()->findOrFail($registration->category_id);
            $capacity = $category->capacity ?? $edition->max_participants;
            if ($capacity !== null) {
                $approved = CompetitionRegistration::query()->where('edition_id', $edition->id)->where('status', 'APPROVED')
                    ->when($category->capacity !== null, fn (Builder $query) => $query->where('category_id', $category->id))->count();
                if ($approved >= (int) $capacity) {
                    throw ValidationException::withMessages(['waitlist' => 'Kapasitas telah terisi kembali. Hubungi koordinator.']);
                }
            }
            $evaluation = CompetitionEligibilityEvaluation::query()->where('registration_id', $registration->id)->latest('id')->first();
            if ($evaluation?->result !== 'ELIGIBLE') {
                throw ValidationException::withMessages(['eligibility' => 'Pendaftaran waitlist tidak lagi eligible.']);
            }
            $this->assertRequiredDocumentsVerified($registration);
            $this->assertConsentApproved($registration, $edition, $category);
            if (($edition->requires_payment || (float) ($registration->fee_amount ?? 0) > 0) && ! in_array($registration->payment_status, ['PAID', 'WAIVED'], true)) {
                throw ValidationException::withMessages(['payment_status' => 'Pembayaran belum lunas atau dibebaskan.']);
            }
            $from = (string) $registration->status;
            $participant = $this->createParticipant($registration, $category, $actor, false, null);
            $registration->forceFill(['status' => 'APPROVED', 'waitlist_position' => null, 'approved_by' => $actor->id, 'approved_at' => now(), 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
            $waitlist->forceFill(['status' => 'PROMOTED', 'confirmed_at' => now(), 'promoted_at' => now(), 'promoted_by' => $actor->id])->save();
            $this->history($registration, $from, 'APPROVED', $actor, 'Peserta mengonfirmasi tawaran waitlist.');
            $this->audit->record('WaitlistParticipantPromoted', $actor, $registration, $edition, before: ['status' => $from], after: ['status' => 'APPROVED', 'participant_id' => $participant->id]);

            return $registration->refresh();
        });
    }

    public function createTeam(CompetitionEdition $edition, array $data, User $actor): CompetitionTeam
    {
        $this->scope->assertEdition($actor, $edition);
        if (! $actor->can('team.manage') && ! $actor->can('registration.manage')) {
            abort(403);
        }
        $category = CompetitionCategory::query()->where('edition_id', $edition->id)->where('participant_mode', 'TEAM')->findOrFail($data['category_id']);
        $members = (array) ($data['members'] ?? []);
        $this->assertTeamSize($category, count(array_filter($members, fn (array $member) => ! ($member['is_substitute'] ?? false))));

        return DB::transaction(function () use ($edition, $category, $data, $members, $actor): CompetitionTeam {
            $team = CompetitionTeam::query()->create([
                'edition_id' => $edition->id,
                'category_id' => $category->id,
                'code' => $data['code'] ?? 'TEAM-'.Str::upper(Str::random(6)),
                'name' => trim($data['name']),
                'institution_name' => $data['institution_name'] ?? null,
                'campus_id' => $data['campus_id'] ?? $edition->campus_id,
                'coach_employee_id' => $data['coach_employee_id'] ?? null,
                'mentor_employee_id' => $data['mentor_employee_id'] ?? null,
                'manager_name' => $data['manager_name'] ?? null,
                'manager_contact' => $data['manager_contact'] ?? null,
                'status' => 'DRAFT',
            ]);
            $captains = 0;
            $uniqueStudents = [];
            foreach ($members as $index => $member) {
                $studentId = (int) $member['student_id'];
                if (isset($uniqueStudents[$studentId])) {
                    throw ValidationException::withMessages(["members.{$index}.student_id" => 'Siswa tidak boleh tercatat dua kali pada roster tim.']);
                }
                $uniqueStudents[$studentId] = true;
                $snapshot = $this->students->snapshot($studentId);
                if ($snapshot === null || ! ($snapshot['active'] ?? $snapshot['is_active'] ?? true)) {
                    throw ValidationException::withMessages(["members.{$index}.student_id" => 'Anggota harus siswa aktif.']);
                }
                $captain = (bool) ($member['is_captain'] ?? false);
                if ($captain) {
                    $captains++;
                }
                CompetitionTeamMember::query()->create([
                    'team_id' => $team->id,
                    'student_id' => $studentId,
                    'member_role' => strtoupper((string) ($member['role'] ?? 'MEMBER')),
                    'position' => $member['position'] ?? null,
                    'jersey_number' => $member['jersey_number'] ?? null,
                    'is_captain' => $captain,
                    'is_substitute' => (bool) ($member['is_substitute'] ?? false),
                    'eligibility_status' => 'ELIGIBLE',
                    'joined_at' => now(),
                ]);
            }
            if ($captains > 1) {
                throw ValidationException::withMessages(['members' => 'Roster hanya boleh memiliki satu kapten.']);
            }
            $captainMember = CompetitionTeamMember::query()->where('team_id', $team->id)->where('is_captain', true)->first();
            if ($captainMember) {
                $team->forceFill(['metadata' => array_merge((array) $team->metadata, ['captain_student_id' => $captainMember->student_id])])->save();
            }
            $this->audit->record('TeamCreated', $actor, $team, $edition, after: ['member_count' => count($members)]);

            return $team->refresh();
        });
    }

    public function lockRoster(CompetitionTeam $team, User $actor): CompetitionTeam
    {
        $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($team->edition_id));
        if (! $actor->can('team.manage')) {
            abort(403);
        }
        if ($team->roster_locked) {
            return $team;
        }
        $category = CompetitionCategory::query()->findOrFail($team->category_id);
        $count = CompetitionTeamMember::query()->where('team_id', $team->id)->whereNull('left_at')->where('is_substitute', false)->count();
        $this->assertTeamSize($category, $count);
        $team->forceFill(['roster_locked' => true, 'roster_locked_at' => now(), 'roster_locked_by' => $actor->id, 'status' => 'ACTIVE'])->save();
        $this->audit->record('TeamRosterLocked', $actor, $team, after: ['roster_locked' => true, 'members' => $count]);

        return $team->refresh();
    }

    public function participants(CompetitionEdition $edition, array $filters, User $actor)
    {
        $this->scope->assertEdition($actor, $edition);

        return CompetitionParticipant::query()->where('edition_id', $edition->id)
            ->when(! empty($filters['category_id']), fn (Builder $query) => $query->where('category_id', $filters['category_id']))
            ->when(! empty($filters['status']), fn (Builder $query) => $query->where('status', strtoupper((string) $filters['status'])))
            ->orderBy('display_name')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))));
    }

    public function offerNextWaitlist(int $categoryId, User $actor): ?CompetitionWaitlist
    {
        $waitlist = CompetitionWaitlist::query()->where('category_id', $categoryId)->where('status', 'WAITING')
            ->orderByDesc('priority')->orderBy('position')->lockForUpdate()->first();
        if ($waitlist === null) {
            return null;
        }
        $waitlist->forceFill(['status' => 'OFFERED', 'offered_at' => now(), 'offer_expires_at' => now()->addDay()])->save();
        $registration = CompetitionRegistration::query()->find($waitlist->registration_id);
        if ($registration !== null) {
            $this->queueNotification(CompetitionEdition::query()->find($registration->edition_id), $registration, 'WaitlistSlotOffered', $this->participantUserIds($registration), ['position' => $waitlist->position]);
        }
        $this->audit->record('WaitlistSlotOffered', $actor, $waitlist, after: ['position' => $waitlist->position]);

        return $waitlist;
    }

    private function createParticipant(CompetitionRegistration $registration, CompetitionCategory $category, User $actor, bool $override, ?string $reason): CompetitionParticipant
    {
        $existing = CompetitionParticipant::query()->where('registration_id', $registration->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        if ($registration->participant_type === 'TEAM') {
            $team = CompetitionTeam::query()->where('edition_id', $registration->edition_id)->where('category_id', $category->id)->findOrFail($registration->team_id);
            if (! $team->roster_locked) {
                throw ValidationException::withMessages(['team_id' => 'Kunci roster tim sebelum menyetujui pendaftaran beregu.']);
            }

            return CompetitionParticipant::query()->create([
                'edition_id' => $registration->edition_id, 'category_id' => $category->id, 'registration_id' => $registration->id,
                'team_id' => $team->id, 'participant_type' => 'TEAM', 'display_name' => $team->name,
                'institution_name' => $team->institution_name, 'campus_id' => $team->campus_id, 'status' => 'ACTIVE',
                'qualification_status' => 'PENDING', 'eligibility_status' => $override ? 'OVERRIDE_APPROVED' : 'ELIGIBLE',
                'is_active' => true, 'metadata' => $override ? ['eligibility_override_reason' => $reason, 'overridden_by' => $actor->id] : null,
            ]);
        }
        $snapshot = $registration->student_id ? $this->students->snapshot((int) $registration->student_id) : null;

        return CompetitionParticipant::query()->create([
            'edition_id' => $registration->edition_id, 'category_id' => $category->id, 'registration_id' => $registration->id,
            'participant_type' => $registration->participant_type, 'student_id' => $registration->student_id, 'employee_id' => $registration->employee_id,
            'display_name' => $snapshot['name'] ?? $snapshot['full_name'] ?? $registration->external_participant_name,
            'institution_name' => $registration->institution_name, 'campus_id' => $snapshot['campus_id'] ?? null,
            'status' => 'ACTIVE', 'qualification_status' => 'PENDING',
            'eligibility_status' => $override ? 'OVERRIDE_APPROVED' : 'ELIGIBLE', 'is_active' => true,
            'metadata' => $override ? ['eligibility_override_reason' => $reason, 'overridden_by' => $actor->id] : null,
        ]);
    }

    private function assertRequiredDocumentsVerified(CompetitionRegistration $registration): void
    {
        $required = CompetitionDocumentRequirement::query()->where('category_id', $registration->category_id)->where('status', 'ACTIVE')->where('is_required', true)->get();
        foreach ($required as $requirement) {
            if (! CompetitionRegistrationDocument::query()->where('registration_id', $registration->id)->where('requirement_id', $requirement->id)->where('status', 'VERIFIED')->exists()) {
                throw ValidationException::withMessages(['documents' => 'Dokumen wajib belum diverifikasi: '.$requirement->name.'.']);
            }
        }
    }

    private function assertConsentApproved(CompetitionRegistration $registration, CompetitionEdition $edition, CompetitionCategory $category): void
    {
        if (! ($edition->requires_parent_consent || $category->requires_parent_consent) || $registration->student_id === null) {
            return;
        }
        if (! CompetitionConsent::query()->where('registration_id', $registration->id)->where('consent_type', 'PARENT')->where('status', 'APPROVED')->exists()) {
            throw ValidationException::withMessages(['consent' => 'Persetujuan orang tua belum diberikan.']);
        }
    }

    private function assertRegistrationOpen(CompetitionEdition $edition): void
    {
        if ($edition->status !== 'REGISTRATION_OPEN') {
            throw ValidationException::withMessages(['edition' => 'Pendaftaran edition ini sedang ditutup.']);
        }
        if ($edition->registration_opens !== null && $edition->registration_opens->isFuture()) {
            throw ValidationException::withMessages(['registration_opens' => 'Pendaftaran belum dibuka.']);
        }
        if ($edition->registration_closes !== null && $edition->registration_closes->isPast()) {
            throw ValidationException::withMessages(['registration_closes' => 'Batas pendaftaran sudah lewat.']);
        }
    }

    private function assertParticipantTypeAllowed(CompetitionEdition $edition, string $type): void
    {
        if ($type === 'EXTERNAL' && ! $edition->allows_external) {
            throw ValidationException::withMessages(['participant_type' => 'Peserta eksternal tidak diperbolehkan pada edition ini.']);
        }
        if (in_array($type, ['STUDENT', 'TEAM', 'EMPLOYEE', 'TEACHER'], true) && ! $edition->allows_internal) {
            throw ValidationException::withMessages(['participant_type' => 'Peserta internal tidak diperbolehkan pada edition ini.']);
        }
        if (! in_array($type, ['STUDENT', 'TEAM', 'EMPLOYEE', 'TEACHER', 'EXTERNAL', 'ORGANIZATION'], true)) {
            throw ValidationException::withMessages(['participant_type' => 'Jenis peserta tidak dikenal.']);
        }
    }

    private function assertRegistrationAuthority(User $actor, string $participantType, ?int $studentId, array $data): void
    {
        if ($actor->canAny(['competition.create', 'registration.review', 'registration.approve', 'registration.manage'])) {
            return;
        }
        if ($participantType !== 'STUDENT' || $studentId === null) {
            abort(403, 'Pendaftaran eksternal/tim hanya dapat dibuat oleh pengelola kompetisi.');
        }
        $userable = $actor->userable;
        $studentMorph = (new Student)->getMorphClass();
        if ($userable instanceof Student && $actor->userable_type === $studentMorph && (int) $actor->userable_id === $studentId) {
            return;
        }
        if ($this->parents->canActFor((int) $actor->id, $studentId)) {
            return;
        }
        abort(403, 'Siswa hanya dapat mendaftar sendiri, dan wali hanya dapat mendaftarkan anak yang terhubung.');
    }

    private function assertOwnerOrManager(CompetitionRegistration $registration, User $actor): void
    {
        $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($registration->edition_id));
        if ($actor->canAny(['registration.review', 'registration.approve', 'registration.manage', 'competition.create'])) {
            return;
        }
        if ((int) $registration->submitted_by_user_id === (int) $actor->id) {
            return;
        }
        if ($registration->student_id !== null && $this->parents->canActFor((int) $actor->id, (int) $registration->student_id)) {
            return;
        }
        abort(403);
    }

    private function assertCanReview(CompetitionRegistration $registration, User $actor): void
    {
        if (! $actor->can('registration.review') && ! $actor->can('registration.approve')) {
            abort(403);
        }
        $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($registration->edition_id));
    }

    private function history(CompetitionRegistration $registration, ?string $from, string $to, User $actor, ?string $reason): void
    {
        CompetitionRegistrationStatusHistory::query()->create([
            'registration_id' => $registration->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason,
            'actor_id' => $actor->id, 'actor_name' => $actor->name, 'source' => 'WEB',
        ]);
    }

    private function nextRegistrationNumber(CompetitionEdition $edition): string
    {
        do {
            $number = strtoupper('REG-'.$edition->year.'-'.Str::random(7));
        } while (CompetitionRegistration::query()->where('registration_number', $number)->exists());

        return $number;
    }

    private function participantUserIds(CompetitionRegistration $registration): array
    {
        if ($registration->student_id === null) {
            return [];
        }
        $student = Student::query()->find($registration->student_id);

        return $student?->user?->id ? [(int) $student->user->id] : [];
    }

    private function queueNotification(?CompetitionEdition $edition, CompetitionRegistration $registration, string $event, array $recipientUserIds, array $payload): void
    {
        foreach (array_unique(array_filter($recipientUserIds)) as $userId) {
            $notification = CompetitionNotification::query()->firstOrCreate(
                ['idempotency_key' => $event.':'.$registration->id.':'.$userId],
                [
                    'edition_id' => $edition?->id, 'registration_id' => $registration->id,
                    'participant_id' => null, 'notification_event' => $event, 'channel' => 'IN_APP',
                    'recipient_type' => 'USER', 'recipient_id' => $userId, 'subject' => $event,
                    'body' => null, 'status' => 'PENDING', 'payload' => $payload,
                ],
            );
            if ($notification->status === 'PENDING') {
                SendCompetitionNotificationJob::dispatch((int) $notification->id)->afterCommit();
            }
        }
    }

    private function assertTeamSize(CompetitionCategory $category, int $count): void
    {
        if ($category->min_team_size !== null && $count < (int) $category->min_team_size) {
            throw ValidationException::withMessages(['members' => 'Tim minimal harus memiliki '.$category->min_team_size.' anggota aktif.']);
        }
        if ($category->max_team_size !== null && $count > (int) $category->max_team_size) {
            throw ValidationException::withMessages(['members' => 'Tim melebihi batas '.$category->max_team_size.' anggota aktif.']);
        }
    }
}
