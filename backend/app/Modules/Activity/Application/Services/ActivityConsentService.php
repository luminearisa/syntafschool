<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Contracts\ParentProviderInterface;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\ConsentScope;
use App\Modules\Activity\Domain\Enums\ConsentStatus;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityConsent;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRegistration;
use Illuminate\Support\Facades\DB;

/**
 * Persetujuan orang tua (§25, §61, §10\).
 *
 * Batas kewenangan ditegakkan di sini: orang tua dapat **menyetujui atau
 * menolak**, tidak lebih. Ia tidak dapat mengubah kehadiran, penilaian,
 * maupun keanggotaan — permintaan seperti itu akan ditolak sebelum menyentuh
 * data. Setiap keputusan meninggalkan jejak audit dan tercatat di riwayat
 * persetujuan beserta waktu dan catatannya.
 */
class ActivityConsentService
{
    public function __construct(
        private readonly ParentProviderInterface $parents,
        private readonly ActivityAuditService $audit,
        private readonly ActivityNotificationService $notifications,
    ) {}

    /** Dipakai orang tua di portal: daftar persetujuan yang menunggu keputusannya. */
    public function pendingForUser(User $user): array
    {
        $studentIds = $this->authorisedStudentIds($user);

        if ($studentIds === []) {
            return [];
        }

        return ActivityConsent::query()
            ->with(['program:id,name', 'student:id,full_name', 'registration:id,code'])
            ->whereIn('student_id', $studentIds)
            ->where('decision', ConsentStatus::PENDING->value)
            ->orderBy('requested_at')
            ->get()
            ->map(fn (ActivityConsent $consent) => $this->present($consent))
            ->all();
    }

    /** @return list<array> */
    public function forRegistration(ActivityRegistration $registration): array
    {
        return $registration->consents()
            ->with(['program:id,name', 'student:id,full_name'])
            ->get()
            ->map(fn (ActivityConsent $consent) => $this->present($consent))
            ->all();
    }

    /**
     * Minta persetujuan untuk satu pendaftaran.
     *
     * Bila siswa tidak memiliki wali berakun, persetujuan tetap dibuat sebagai
     * baris tanpa akun — sehingga sekolah melihat bahwa persetujuan tertulis
     * masih diperlukan, bukan menganggapnya sudah ada.
     *
     * @param  list<string>  $scopes
     * @return list<ActivityConsent>
     */
    public function request(ActivityRegistration $registration, array $scopes = [ConsentScope::PARTICIPATION->value], ?User $actor = null): array
    {
        $program = $registration->program;

        if ($program === null) {
            throw ActivityValidationException::withErrors(['Program pendaftaran tidak ditemukan.']);
        }

        $guardians = $this->parents->guardiansOf((int) $registration->student_id);

        return DB::transaction(function () use ($registration, $program, $scopes, $guardians, $actor) {
            $created = [];

            foreach ($scopes as $scope) {
                if ($guardians === []) {
                    $created[] = $this->createConsent($registration, $program, null, null, $scope, $actor);
                } else {
                    foreach ($guardians as $guardian) {
                        $created[] = $this->createConsent(
                            $registration,
                            $program,
                            (int) $guardian['guardian_id'],
                            $guardian['user_id'] === null ? null : (int) $guardian['user_id'],
                            $scope,
                            $actor,
                        );
                    }
                }
            }

            $this->notifications->notify([
                'event' => 'CONSENT_REQUIRED',
                'program_id' => $program->id,
                'student_id' => $registration->student_id,
                'body' => 'Persetujuan Anda diperlukan untuk keikutsertaan pada '.$program->name.'.',
                'recipient_user_ids' => array_values(array_filter(array_map(
                    fn (ActivityConsent $consent) => $consent->parent_user_id,
                    $created,
                ))),
                'marker' => 'consent|'.$registration->code,
            ]);

            $this->audit->record('consent.requested', [
                'subject' => $registration,
                'program_id' => $program->id,
                'student_id' => $registration->student_id,
                'after' => ['scopes' => $scopes, 'count' => count($created)],
                'actor' => $actor?->id,
            ]);

            return $created;
        });
    }

    /** Keputusan orang tua — inti alur persetujuan. */
    public function decide(ActivityConsent $consent, User $actor, string $decision, ?string $notes = null): ActivityConsent
    {
        $decision = strtoupper($decision);

        if (! in_array($decision, [ConsentStatus::APPROVED->value, ConsentStatus::DECLINED->value, ConsentStatus::REVOKED->value], true)) {
            throw ActivityValidationException::withErrors(['Keputusan persetujuan harus APPROVED, DECLINED, atau REVOKED.']);
        }

        if (! $this->canDecide($consent, $actor)) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang memberi persetujuan untuk siswa ini.');
        }

        return DB::transaction(function () use ($consent, $actor, $decision, $notes) {
            $locked = ActivityConsent::query()->lockForUpdate()->findOrFail($consent->id);

            if ($locked->decision !== ConsentStatus::PENDING->value && $locked->decision?->value !== ConsentStatus::PENDING->value) {
                throw ActivityValidationException::withErrors([
                    'Persetujuan ini sudah diputuskan ('.$locked->decision->label().') — keputusan tidak dapat ditimpa.',
                ]);
            }

            $before = $locked->attributesToArray();

            $locked->forceFill([
                'decision' => $decision,
                'notes' => $notes,
                'decided_at' => now(),
                'parent_user_id' => $locked->parent_user_id ?? $actor->id,
            ])->save();

            $this->audit->record(AuditAction::CONSENT_DECIDED, [
                'subject' => $locked,
                'program_id' => $locked->program_id,
                'student_id' => $locked->student_id,
                'before' => $before,
                'after' => $locked,
                'actor' => $actor->id,
            ]);

            $this->notifications->notify([
                'event' => 'CONSENT_DECIDED',
                'program_id' => $locked->program_id,
                'student_id' => $locked->student_id,
                'body' => 'Persetujuan '.$locked->scope->label().' telah '.$locked->decision->label().'.',
                'audience' => 'STUDENT',
                'marker' => 'consent-decided|'.$locked->id,
            ]);

            return $locked->refresh();
        });
    }

    /** Apakah seluruh persetujuan wajib untuk pendaftaran ini sudah disetujui. */
    public function allApproved(ActivityRegistration $registration): bool
    {
        $consents = $registration->consents()->get();

        if ($consents->isEmpty()) {
            return true;
        }

        return $consents->every(fn (ActivityConsent $consent) => $consent->decision === ConsentStatus::APPROVED);
    }

    public function hasPending(ActivityRegistration $registration): bool
    {
        return $registration->consents()
            ->where('decision', ConsentStatus::PENDING->value)
            ->exists();
    }

    /** Kedaluwarsakan persetujuan yang melewati batas waktu (dijalankan terjadwal). */
    public function expireOverdue(): int
    {
        return ActivityConsent::query()
            ->where('decision', ConsentStatus::PENDING->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['decision' => ConsentStatus::EXPIRED->value, 'updated_at' => now()]);
    }

    public function canDecide(ActivityConsent $consent, User $actor): bool
    {
        if ($actor->can('activity.consent.decide') && $actor->can('activity.program.manage')) {
            return true;
        }

        if ($consent->parent_user_id !== null && (int) $consent->parent_user_id === (int) $actor->id) {
            return true;
        }

        return $this->parents->canActFor((int) $actor->id, (int) $consent->student_id);
    }

    /** @return list<int> */
    protected function authorisedStudentIds(User $user): array
    {
        $guardianId = $user->userable_type === (new \App\Models\Guardian)->getMorphClass() && $user->userable_id !== null
            ? (int) $user->userable_id
            : null;

        if ($guardianId !== null) {
            return \App\Models\StudentGuardian::query()
                ->where('guardian_id', $guardianId)
                ->pluck('student_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->parents->childrenOfUser((int) $user->id);
    }

    protected function createConsent(
        ActivityRegistration $registration,
        ActivityProgram $program,
        ?int $guardianId,
        ?int $parentUserId,
        string $scope,
        ?User $actor,
    ): ActivityConsent {
        return ActivityConsent::query()->create([
            'registration_id' => $registration->id,
            'program_id' => $program->id,
            'student_id' => $registration->student_id,
            'guardian_id' => $guardianId,
            'parent_user_id' => $parentUserId,
            'decision' => ConsentStatus::PENDING->value,
            'scope' => $scope,
            'requested_at' => now(),
            'expires_at' => now()->addDays((int) config('activity.consent.expiry_days', 14)),
            'idempotency_key' => 'consent|'.$registration->id.'|'.($guardianId ?? 'none').'|'.$scope,
        ]);
    }

    protected function present(ActivityConsent $consent): array
    {
        return [
            'id' => (int) $consent->id,
            'registration_id' => $consent->registration_id === null ? null : (int) $consent->registration_id,
            'registration_code' => $consent->registration?->code,
            'program_id' => (int) $consent->program_id,
            'program_name' => $consent->program?->name,
            'student_id' => (int) $consent->student_id,
            'student_name' => $consent->student?->full_name,
            'scope' => $consent->scope?->value,
            'scope_label' => $consent->scope?->label(),
            'decision' => $consent->decision?->value,
            'decision_label' => $consent->decision?->label(),
            'notes' => $consent->notes,
            'requested_at' => $consent->requested_at?->toIso8601String(),
            'decided_at' => $consent->decided_at?->toIso8601String(),
            'expires_at' => $consent->expires_at?->toIso8601String(),
            'can_decide' => $consent->decision?->value === ConsentStatus::PENDING->value,
        ];
    }
}
