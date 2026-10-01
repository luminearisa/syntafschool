<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\RegistrationStatus;
use App\Modules\Activity\Domain\Enums\SelectionDecision;
use App\Modules\Activity\Domain\Enums\SelectionMethod;
use App\Modules\Activity\Domain\Enums\WaitlistStatus;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRegistration;
use App\Modules\Activity\Models\ActivityRegistrationHistory;
use App\Modules\Activity\Models\ActivitySelectionResult;
use App\Modules\Activity\Models\ActivityWaitlistEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pendaftaran → seleksi → keanggotaan (§21–24, §daftar tunggu).
 *
 * Tiga jaminan yang menjadi tanggung jawab kelas ini:
 *
 *  1. **Tidak ada pendaftaran ganda.** Satu siswa hanya punya satu pendaftaran
 *     hidup per program per periode — ditegakkan indeks unik, bukan sekadar
 *     pemeriksaan di kode. Mengirim ulang permintaan mengembalikan yang sama.
 *  2. **Kursi terakhir tidak diperebutkan.** Pembuatan keanggotaan mengunci
 *     baris program (`lockForUpdate`) sehingga dua siswa yang mendaftar pada
 *     saat bersamaan tidak bisa sama-sama mendapat kursi terakhir; yang kalah
 *     masuk daftar tunggu.
 *  3. **Setiap perubahan status punya jejak.** Riwayat pendaftaran mencatat
 *     siapa memutuskan apa, kapan, dan dengan catatan apa.
 */
class ActivityRegistrationService
{
    public function __construct(
        private readonly ActivityRequirementService $requirements,
        private readonly ActivityConsentService $consents,
        private readonly ActivityMembershipService $memberships,
        private readonly ActivityNotificationService $notifications,
        private readonly ActivityAuditService $audit,
        private readonly ActivityAccessService $access,
        private readonly ActivityIntegrationService $integrations,
    ) {}

    /** @return LengthAwarePaginator */
    public function paginate(array $filters = [], ?User $viewer = null): LengthAwarePaginator
    {
        $query = ActivityRegistration::query()
            ->with([
                'program:id,name,code,maximum_capacity,selection_method,requires_parent_consent,requires_payment,fee_amount',
                'period:id,name',
                'student:id,full_name,nis',
                'decider:id,name',
            ])
            ->when(isset($filters['program_id']), fn ($q) => $q->where('program_id', $filters['program_id']))
            ->when(isset($filters['period_id']), fn ($q) => $q->where('period_id', $filters['period_id']))
            ->when(isset($filters['student_id']), fn ($q) => $q->where('student_id', $filters['student_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['mine'] ?? false) === true && $viewer !== null, fn ($q) => $q->where('student_id', $this->access->studentIdForUser($viewer)))
            ->orderByDesc('id');

        if ($viewer !== null && ! $viewer->can('activity.registration.view')) {
            $studentIds = $this->access->visibleStudentIds($viewer);
            $query->whereIn('student_id', $studentIds === [] ? [-1] : $studentIds);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * Ajukan pendaftaran (idempoten).
     *
     * @param  array<string, mixed>  $payload  motivation, recommendation, idempotency_key, allow_over_limit
     */
    public function start(User $actor, ActivityProgram $program, int $studentId, array $payload = []): ActivityRegistration
    {
        $this->authoriseStart($actor, $studentId);

        $idempotencyKey = $payload['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = ActivityRegistration::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $existing = ActivityRegistration::query()
            ->where('program_id', $program->id)
            ->where('student_id', $studentId)
            ->where('period_id', $program->period_id)
            ->whereNotIn('status', [RegistrationStatus::CANCELLED->value, RegistrationStatus::REJECTED->value])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $evaluation = $this->requirements->evaluate($program, $studentId, [
            'recommendation' => $payload['recommendation'] ?? null,
            'allow_over_limit' => (bool) ($payload['allow_over_limit'] ?? $actor->can('activity.registration.override')),
            'allow_over_capacity' => (bool) ($payload['allow_over_capacity'] ?? false),
        ]);

        if (! $evaluation['passed']) {
            $messages = array_map(fn (array $failure) => $failure['message'], $evaluation['failures']);

            $this->audit->record('registration.rejected_by_rules', [
                'program_id' => $program->id,
                'student_id' => $studentId,
                'after' => ['failures' => $evaluation['failures'], 'warnings' => $evaluation['warnings']],
                'actor' => $actor->id,
            ]);

            throw ActivityValidationException::withErrors($messages, 'Pendaftaran belum memenuhi syarat aktivitas.');
        }

        return DB::transaction(function () use ($actor, $program, $studentId, $payload, $evaluation, $idempotencyKey) {
            // Kursi diperiksa di dalam kunci transaksi supaya keputusan
            // "diterima vs daftar tunggu" tidak dapat berlomba.
            $locked = ActivityProgram::query()->lockForUpdate()->findOrFail($program->id);
            $capacity = $this->requirements->capacity($locked);
            $waitlisted = $capacity['is_full'] && ! ($payload['allow_over_capacity'] ?? false);

            $needsConsent = (bool) $locked->requires_parent_consent && ! ($payload['consent_waived'] ?? false);

            $status = match (true) {
                $waitlisted => RegistrationStatus::WAITLISTED->value,
                $needsConsent => RegistrationStatus::UNDER_REVIEW->value,
                $locked->selection_method === SelectionMethod::OPEN => RegistrationStatus::APPROVED->value,
                default => RegistrationStatus::SUBMITTED->value,
            };

            $registration = ActivityRegistration::query()->create([
                'code' => $this->nextCode(),
                'program_id' => $locked->id,
                'period_id' => $locked->period_id,
                'student_id' => $studentId,
                'status' => $status,
                'source' => $payload['source'] ?? $this->sourceFor($actor, $studentId),
                'requirement_snapshot' => $evaluation['snapshot'],
                'validation_result' => [
                    'failures' => $evaluation['failures'],
                    'warnings' => $evaluation['warnings'],
                    'checks' => $evaluation['checks'],
                    'conflicts' => $evaluation['conflicts'],
                    'capacity' => $evaluation['capacity'],
                ],
                'submitted_at' => now(),
                'motivation' => $payload['motivation'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'priority' => $payload['priority'] ?? 0,
            ]);

            $this->writeHistory($registration, null, $status, $actor, 'Pendaftaran diterima sistem.');
            $this->audit->record(AuditAction::REGISTRATION_SUBMITTED, [
                'subject' => $registration,
                'program_id' => $locked->id,
                'student_id' => $studentId,
                'after' => $registration,
                'actor' => $actor->id,
            ]);

            if ($waitlisted) {
                $entry = $this->enqueueWaitlist($registration, $locked);
                $registration->forceFill(['waitlist_position' => $entry->position])->save();

                $this->audit->record(AuditAction::REGISTRATION_WAITLISTED, [
                    'subject' => $registration,
                    'program_id' => $locked->id,
                    'student_id' => $studentId,
                    'after' => ['position' => $entry->position],
                    'actor' => $actor->id,
                ]);
            }

            if ($needsConsent) {
                $this->consents->request($registration, [\App\Modules\Activity\Domain\Enums\ConsentScope::PARTICIPATION->value], $actor);
            }

            if ($locked->requires_payment && (float) $locked->fee_amount > 0) {
                $this->requestCharge($registration, $locked, (float) $locked->fee_amount, $actor);
            }

            $this->notifications->registrationSubmitted($registration);

            // Jalur cepat: metode OPEN tanpa syarat persetujuan → langsung anggota.
            if ($registration->status === RegistrationStatus::APPROVED) {
                $this->memberships->createFromRegistration($registration->refresh(), $actor);
            }

            return $registration->refresh();
        });
    }

    /** Ajukan pendaftaran yang masih draf (dipakai layar admin yang menyiapkan berkas lebih dahulu). */
    public function submit(ActivityRegistration $registration, User $actor): ActivityRegistration
    {
        if ($registration->status !== RegistrationStatus::DRAFT) {
            throw ActivityConflictException::make('Pendaftaran ini sudah diajukan ('.$registration->status->label().').');
        }

        $evaluation = $this->requirements->evaluate(
            $registration->program,
            (int) $registration->student_id,
            ['allow_over_limit' => $actor->can('activity.registration.override')],
        );

        if (! $evaluation['passed']) {
            throw ActivityValidationException::withErrors(
                array_map(fn (array $failure) => $failure['message'], $evaluation['failures']),
                'Pendaftaran belum memenuhi syarat aktivitas.',
            );
        }

        return $this->transition($registration, RegistrationStatus::SUBMITTED, $actor, 'Pendaftaran diajukan.', [
            'validation_result' => $evaluation,
        ]);
    }

    /**
     * Keputusan panitia/pembina.
     *
     * @param  array{notes?: string, allow_over_capacity?: bool}  $payload
     */
    public function decide(ActivityRegistration $registration, User $actor, string $decision, array $payload = []): ActivityRegistration
    {
        $decision = strtoupper($decision);

        if (! $this->access->canDecideRegistration($actor, $registration)) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang memutuskan pendaftaran ini.');
        }

        return DB::transaction(function () use ($registration, $actor, $decision, $payload) {
            $locked = ActivityRegistration::query()->lockForUpdate()->findOrFail($registration->id);

            if ($locked->status->isFinal()) {
                throw ActivityConflictException::make(
                    'Pendaftaran sudah berstatus '.$locked->status->label().' dan tidak dapat diputuskan lagi.',
                    ['status' => $locked->status->value],
                );
            }

            return match ($decision) {
                'APPROVE' => $this->approve($locked, $actor, $payload),
                'REJECT' => $this->reject($locked, $actor, $payload),
                'WAITLIST' => $this->waitlist($locked, $actor, $payload),
                'REVISE' => $this->revise($locked, $actor, $payload),
                'SELECT' => $this->transition($locked, RegistrationStatus::SELECTED, $actor, $payload['notes'] ?? 'Dinyatakan lolos seleksi.', []),
                default => throw ActivityValidationException::withErrors(['Keputusan tidak dikenal: '.$decision.'.']),
            };
        });
    }

    /** Catat hasil satu tahap seleksi (tes coba, audisi, wawancara). */
    public function recordSelection(ActivityRegistration $registration, User $actor, array $payload): ActivitySelectionResult
    {
        if (! $this->access->canDecideRegistration($actor, $registration)) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang mencatat seleksi pendaftaran ini.');
        }

        $result = ActivitySelectionResult::query()->updateOrCreate(
            [
                'registration_id' => $registration->id,
                'stage' => $payload['stage'],
            ],
            [
                'score' => $payload['score'] ?? null,
                'rank' => $payload['rank'] ?? null,
                'decision' => $payload['decision'] ?? SelectionDecision::PENDING->value,
                'notes' => $payload['notes'] ?? null,
                'criteria' => $payload['criteria'] ?? null,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ],
        );

        $this->audit->record('selection.recorded', [
            'subject' => $registration,
            'program_id' => $registration->program_id,
            'student_id' => $registration->student_id,
            'after' => $result,
            'actor' => $actor->id,
        ]);

        if (($payload['apply_decision'] ?? true) === true && ($payload['decision'] ?? null) !== null) {
            match ($payload['decision']) {
                SelectionDecision::SELECTED->value => $this->decide($registration, $actor, 'APPROVE', ['notes' => $payload['notes'] ?? 'Lolos seleksi.']),
                SelectionDecision::WAITLISTED->value => $this->decide($registration, $actor, 'WAITLIST', ['notes' => $payload['notes'] ?? null]),
                SelectionDecision::REJECTED->value => $this->decide($registration, $actor, 'REJECT', ['notes' => $payload['notes'] ?? null]),
                default => null,
            };
        }

        return $result;
    }

    public function cancel(ActivityRegistration $registration, User $actor, ?string $reason = null): ActivityRegistration
    {
        $isOwner = in_array((int) $registration->student_id, array_merge(
            [$this->access->studentIdForUser($actor) ?? -1],
            $this->access->childStudentIds($actor),
        ), true);

        if (! $isOwner && ! $actor->can('activity.registration.cancel')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang membatalkan pendaftaran ini.');
        }

        if ($registration->status->isFinal() && $registration->status !== RegistrationStatus::REJECTED) {
            throw ActivityConflictException::make('Pendaftaran berstatus '.$registration->status->label().' tidak dapat dibatalkan.');
        }

        return $this->transition($registration, RegistrationStatus::CANCELLED, $actor, $reason ?? 'Dibatalkan oleh pemohon.', [
        ]);
    }

    /** @return list<array> */
    public function history(ActivityRegistration $registration): array
    {
        return $registration->histories()
            ->with('actor:id,name')
            ->get()
            ->map(fn (ActivityRegistrationHistory $row) => [
                'from' => $row->from_status,
                'to' => $row->to_status,
                'actor' => $row->actor?->name,
                'notes' => $row->notes,
                'at' => $row->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Ringkasan untuk layar verifikasi kursi/daftar tunggu. */
    public function waitlistOverview(ActivityProgram $program): array
    {
        return ActivityWaitlistEntry::query()
            ->with('student:id,full_name,nis')
            ->where('program_id', $program->id)
            ->orderBy('position')
            ->get()
            ->map(fn (ActivityWaitlistEntry $entry) => [
                'id' => (int) $entry->id,
                'registration_id' => (int) $entry->registration_id,
                'student' => $entry->student?->full_name,
                'position' => (int) $entry->position,
                'status' => $entry->status?->value,
                'promoted_at' => $entry->promoted_at?->toIso8601String(),
            ])
            ->all();
    }

    // ------------------------------------------------------------- internal

    protected function approve(ActivityRegistration $registration, User $actor, array $payload): ActivityRegistration
    {
        if ($this->consents->hasPending($registration) && ! ($payload['ignore_pending_consent'] ?? false)) {
            throw ActivityConflictException::make(
                'Masih ada persetujuan orang tua yang menunggu — keanggotaan belum dapat diaktifkan.',
                ['pending_consents' => count($this->consents->forRegistration($registration))],
            );
        }

        $registration = $this->transition($registration, RegistrationStatus::APPROVED, $actor, $payload['notes'] ?? 'Pendaftaran diterima.', []);

        $this->memberships->createFromRegistration($registration, $actor, $payload['membership'] ?? []);

        return $registration->refresh();
    }

    protected function reject(ActivityRegistration $registration, User $actor, array $payload): ActivityRegistration
    {
        $registration = $this->transition($registration, RegistrationStatus::REJECTED, $actor, $payload['notes'] ?? 'Pendaftaran tidak lolos.', []);

        $this->releaseWaitlist($registration);
        $this->notifications->registrationDecided($registration, 'REJECT');

        return $registration;
    }

    protected function waitlist(ActivityRegistration $registration, User $actor, array $payload): ActivityRegistration
    {
        $registration = $this->transition($registration, RegistrationStatus::WAITLISTED, $actor, $payload['notes'] ?? 'Masuk daftar tunggu.', []);

        $entry = $this->enqueueWaitlist($registration, $registration->program);
        $registration->forceFill(['waitlist_position' => $entry->position])->save();

        $this->notifications->registrationDecided($registration->refresh(), 'WAITLIST');

        return $registration->refresh();
    }

    protected function revise(ActivityRegistration $registration, User $actor, array $payload): ActivityRegistration
    {
        return $this->transition($registration, RegistrationStatus::DRAFT, $actor, $payload['notes'] ?? 'Perlu perbaikan berkas.', []);
    }

    protected function transition(
        ActivityRegistration $registration,
        RegistrationStatus $to,
        User $actor,
        string $notes,
        array $extra,
    ): ActivityRegistration {
        $from = $registration->status;

        $registration->forceFill(array_merge([
            'status' => $to->value,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_notes' => $notes,
        ], $extra))->save();

        $this->writeHistory($registration, $from->value, $to->value, $actor, $notes);

        $this->audit->record(match ($to) {
            RegistrationStatus::APPROVED => AuditAction::REGISTRATION_APPROVED,
            RegistrationStatus::REJECTED => AuditAction::REGISTRATION_REJECTED,
            RegistrationStatus::WAITLISTED => AuditAction::REGISTRATION_WAITLISTED,
            default => 'registration.status_changed',
        }, [
            'subject' => $registration,
            'program_id' => $registration->program_id,
            'student_id' => $registration->student_id,
            'before' => ['status' => $from->value],
            'after' => ['status' => $to->value, 'notes' => $notes],
            'actor' => $actor->id,
        ]);

        return $registration->refresh();
    }

    protected function writeHistory(ActivityRegistration $registration, ?string $from, string $to, ?User $actor, ?string $notes): void
    {
        ActivityRegistrationHistory::query()->create([
            'registration_id' => $registration->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor?->id,
            'notes' => $notes,
        ]);
    }

    protected function enqueueWaitlist(ActivityRegistration $registration, ?ActivityProgram $program): ActivityWaitlistEntry
    {
        $existing = ActivityWaitlistEntry::query()->where('registration_id', $registration->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $position = (int) ActivityWaitlistEntry::query()
            ->where('program_id', $registration->program_id)
            ->where('status', WaitlistStatus::WAITING->value)
            ->max('position') + 1;

        return ActivityWaitlistEntry::query()->create([
            'registration_id' => $registration->id,
            'program_id' => $registration->program_id,
            'student_id' => $registration->student_id,
            'position' => $position,
            'status' => WaitlistStatus::WAITING->value,
        ]);
    }

    protected function releaseWaitlist(ActivityRegistration $registration): void
    {
        ActivityWaitlistEntry::query()
            ->where('registration_id', $registration->id)
            ->where('status', WaitlistStatus::WAITING->value)
            ->update(['status' => WaitlistStatus::CANCELLED->value, 'updated_at' => now()]);
    }

    protected function requestCharge(ActivityRegistration $registration, ActivityProgram $program, float $amount, User $actor): void
    {
        $charge = \App\Modules\Activity\Models\ActivityCharge::query()->firstOrCreate(
            ['idempotency_key' => 'charge|registration|'.$registration->id],
            [
                'program_id' => $program->id,
                'registration_id' => $registration->id,
                'student_id' => $registration->student_id,
                'type' => 'REGISTRATION',
                'description' => 'Biaya pendaftaran '.$program->name,
                'amount' => $amount,
                'currency' => $program->fee_currency ?? 'IDR',
                'status' => 'REQUESTED',
                'requested_by' => $actor->id,
                'requested_at' => now(),
            ],
        );

        $result = $this->integrations->call('FINANCE', 'charge.registration', [
            'subject_type' => \App\Modules\Activity\Models\ActivityCharge::class,
            'subject_id' => $charge->id,
            'student_id' => $registration->student_id,
            'amount' => $amount,
        ], fn () => $this->integrations->finance()->charge([
            'student_id' => $registration->student_id,
            'amount' => $amount,
            'description' => 'Biaya pendaftaran '.$program->name,
            'due_date' => now()->addDays((int) config('activity.finance.due_days', 14))->toDateString(),
        ]));

        $charge->forceFill([
            'status' => $result['status'] ?? 'REQUESTED',
            'external_reference' => $result['reference'] ?? null,
            'metadata' => $result,
        ])->save();

        $this->audit->record(AuditAction::CHARGE_REQUESTED, [
            'subject' => $charge,
            'program_id' => $program->id,
            'student_id' => $registration->student_id,
            'after' => $charge,
            'actor' => $actor->id,
        ]);

        $this->notifications->paymentRequired($program, (int) $registration->student_id, $amount, 'charge|'.$charge->id);
    }

    protected function authoriseStart(User $actor, int $studentId): void
    {
        $own = $this->access->studentIdForUser($actor) === $studentId;
        $child = in_array($studentId, $this->access->childStudentIds($actor), true);

        // Pengelola aktivitas dapat mendaftarkan siswa atas nama sekolah (impor,
        // siswa pindahan, penugasan wajib) — tetapi tidak dapat mendaftarkan
        // dirinya sendiri sebagai anggota bila ia bukan siswa yang bersangkutan.
        $staff = $actor->can('activity.registration.create')
            || $actor->can('activity.registration.decide')
            || $actor->can('activity.membership.manage');

        if (! $staff) {
            throw ActivityValidationException::forbidden('Anda tidak memiliki izin mendaftarkan aktivitas.');
        }

        if (! $own && ! $child && ! $actor->can('activity.registration.decide') && ! $actor->can('activity.membership.manage')) {
            throw ActivityValidationException::forbidden('Anda hanya dapat mendaftarkan diri sendiri atau anak Anda.');
        }
    }

    protected function sourceFor(User $actor, int $studentId): string
    {
        if ($this->access->studentIdForUser($actor) === $studentId) {
            return 'STUDENT';
        }

        return in_array($studentId, $this->access->childStudentIds($actor), true) ? 'PARENT' : 'ADMIN';
    }

    protected function nextCode(): string
    {
        $prefix = 'REG-ACT-'.now()->format('Y').'-';

        $last = ActivityRegistration::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('code');

        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
