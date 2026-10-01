<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\EarlyWarningAction;
use App\Modules\Bk\Domain\Enums\EscalationLevel;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\SlaStatus;
use App\Modules\Bk\Domain\Enums\SlaSubjectType;
use App\Modules\Bk\Models\BkSlaEvent;
use App\Modules\Bk\Models\BkSlaPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SLAY pemantauan waktu tanggap.
 *
 * Ambang waktu tidak ditulis di kode: setiap pekerjaan mencari **kebijakan** yang
 * paling spesifik untuk kategori/prioritas/severity/tipe kasusnya. Cuplikan
 * kebijakan disimpan pada baris SLA, sehingga perubahan kebijakan besok tidak
 * menulis ulang sejarah hari ini.
 *
 * Idempotensi dijaga kunci `dedup_key` per (jenis, id pekerjaan, kebijakan):
 * perintah pemantauan yang berjalan berkali-kali tidak melahirkan SLA baru.
 */
class BkSlaService
{
    public function __construct(
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
    ) {
    }

    /**
     * Memulai pemantauan untuk sebuah pekerjaan (idempoten).
     *
     * @param array{priority?: string|null, severity?: string|null, case_type?: string|null, category?: string|null, assigned_user_id?: int|null, case_id?: int|null, referral_id?: int|null, context?: array} $context
     */
    public function start(SlaSubjectType $subjectType, Model $subject, array $context = []): BkSlaEvent
    {
        $policy = $this->resolvePolicy($subjectType, $context);
        $dedupKey = sprintf('sla:%s:%d:policy:%s', $subjectType->value, $subject->getKey(), $policy?->id ?? 'default');

        $existing = BkSlaEvent::query()->where('dedup_key', $dedupKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $startedAt = $context['started_at'] ?? Carbon::now();
        $hours = $policy?->response_hours ?? $subjectType->defaultHours();
        $warnFraction = (float) config('bk.sla.warning_fraction', 0.75);

        try {
            return BkSlaEvent::query()->create([
                'policy_id' => $policy?->id,
                'policy_code' => $policy?->code,
                'policy_version' => $policy?->version,
                'subject_type' => $subjectType,
                'subject_id' => $subject->getKey(),
                'case_id' => $context['case_id'] ?? null,
                'referral_id' => $context['referral_id'] ?? null,
                'priority' => $context['priority'] ?? null,
                'severity' => $context['severity'] ?? null,
                'case_type' => $context['case_type'] ?? null,
                'case_category' => $context['category'] ?? null,
                'started_at' => $startedAt,
                'due_at' => $startedAt->copy()->addHours($hours),
                'warn_at' => $startedAt->copy()->addHours($hours * $warnFraction),
                'status' => SlaStatus::RUNNING,
                'assigned_user_id' => $context['assigned_user_id'] ?? null,
                'current_level' => EscalationLevel::NONE,
                'policy_snapshot' => $policy === null ? [
                    'source' => 'default',
                    'response_hours' => $subjectType->defaultHours(),
                ] : [
                    'code' => $policy->code,
                    'version' => $policy->version,
                    'response_hours' => $policy->response_hours,
                    'applies_to' => $policy->applies_to,
                    'escalation_level' => $policy->escalation_level?->value,
                ],
                'context' => $context['context'] ?? null,
                'dedup_key' => $dedupKey,
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            $existing = BkSlaEvent::query()->where('dedup_key', $dedupKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** Menandai pekerjaan selesai. Keterlambatan dihitung dari tenggat, bukan dari perasaan. */
    public function complete(SlaSubjectType $subjectType, Model $subject, ?Carbon $at = null): ?BkSlaEvent
    {
        $event = BkSlaEvent::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subject->getKey())
            ->whereIn('status', [SlaStatus::RUNNING->value, SlaStatus::WARNING_SENT->value])
            ->orderByDesc('id')
            ->first();

        if ($event === null) {
            return null;
        }

        $at ??= Carbon::now();
        $breached = $at->greaterThan($event->due_at);

        $event->forceFill([
            'completed_at' => $at,
            'status' => $breached ? SlaStatus::BREACHED : SlaStatus::MET,
            'breached_at' => $breached ? $at : $event->breached_at,
        ])->save();

        return $event->refresh();
    }

    /**
     * Memantau seluruh SLA berjalan: mengirim peringatan menjelang tenggat,
     * menandai keterlampauan, dan (bila diaktifkan) mengeskalasi.
     *
     * Idempoten: peringatan hanya dikirim sekali per baris (`warning_sent`), dan
     * eskalasi memakai kunci unik tersendiri.
     *
     * @return array{warned: int, breached: int, escalated: int}
     */
    public function monitor(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();
        $result = ['warned' => 0, 'breached' => 0, 'escalated' => 0];

        BkSlaEvent::query()
            ->whereIn('status', [SlaStatus::RUNNING->value, SlaStatus::WARNING_SENT->value])
            ->orderBy('due_at')
            ->chunkById(200, function ($events) use ($asOf, &$result): void {
                foreach ($events as $event) {
                    if ($event->warn_at !== null && ! $event->warning_sent && $asOf->greaterThanOrEqualTo($event->warn_at) && $asOf->lessThan($event->due_at)) {
                        $event->forceFill(['status' => SlaStatus::WARNING_SENT, 'warning_sent' => true])->save();

                        $this->notifications->notifyTeam(
                            NotificationEvent::SLA_WARNING,
                            "Tenggat mendekat: {$event->subject_type->label()}",
                            [
                                'sla_event_id' => $event->id,
                                'due_at' => $event->due_at?->toIso8601String(),
                                'subject_type' => $event->subject_type->value,
                            ],
                        );

                        $result['warned']++;
                    }

                    if ($asOf->greaterThan($event->due_at) && ! $event->status->isFinished()) {
                        $event->forceFill(['status' => SlaStatus::BREACHED, 'breached_at' => $asOf])->save();

                        $this->notifications->notifyTeam(
                            NotificationEvent::SLA_BREACHED,
                            "Tenggat terlampaui: {$event->subject_type->label()}",
                            [
                                'sla_event_id' => $event->id,
                                'due_at' => $event->due_at?->toIso8601String(),
                                'priority' => $event->priority?->value,
                            ],
                        );

                        $result['breached']++;

                        if ((bool) config('bk.sla.escalation_enabled', true)) {
                            app(BkEscalationService::class)->escalateFromSla($event, $asOf);
                            $result['escalated']++;
                        }
                    }
                }
            });

        return $result;
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return BkSlaEvent::query()
            ->with(['case:id,case_number,student_id', 'policy:id,code,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'breached' ? $q->breached() : $q->where('status', $status))
            ->when($filters['subject_type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type))
            ->when($filters['only_running'] ?? null, fn ($q) => $q->active())
            ->when($filters['assigned_user_id'] ?? null, fn ($q, $id) => $q->where('assigned_user_id', (int) $id))
            ->orderBy('due_at')
            ->paginate($perPage);
    }

    /**
     * Kebijakan paling spesifik yang berlaku. Semakin banyak penyaring yang
     * dicocokkan kebijakan, semakin ia diprioritaskan — sehingga aturan khusus
     * kasus mendesak menang atas aturan umum.
     */
    public function resolvePolicy(SlaSubjectType $subjectType, array $context): ?BkSlaPolicy
    {
        return BkSlaPolicy::query()
            ->active()
            ->where('subject_type', $subjectType->value)
            ->get()
            ->filter(fn (BkSlaPolicy $policy): bool => $policy->appliesToSubject(
                $context['priority'] ?? null,
                $context['severity'] ?? null,
                $context['case_type'] ?? null,
                $context['category'] ?? null,
            ))
            ->sortByDesc(fn (BkSlaPolicy $policy): int => count(array_filter($policy->applies_to ?? [])))
            ->first();
    }

    /** Tandai SLA dibatalkan (mis. pekerjaan tidak lagi relevan). */
    public function cancel(SlaSubjectType $subjectType, Model $subject, string $reason, ?User $actor = null): void
    {
        $events = BkSlaEvent::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subject->getKey())
            ->whereIn('status', [SlaStatus::RUNNING->value, SlaStatus::WARNING_SENT->value])
            ->get();

        foreach ($events as $event) {
            $event->forceFill([
                'status' => SlaStatus::CANCELLED,
                'context' => array_merge($event->context ?? [], ['cancel_reason' => $reason]),
            ])->save();

            $this->audit->record(AuditAction::UPDATE, $event, $actor, ['sla_cancelled' => $reason]);
        }
    }
}
