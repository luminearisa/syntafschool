<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\EscalationLevel;
use App\Modules\Bk\Domain\Enums\EscalationStatus;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\SlaStatus;
use App\Modules\Bk\Domain\Enums\SlaSubjectType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkEscalation;
use App\Modules\Bk\Models\BkSlaEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eskalasi berjenjang: konselor, koordinator BK, lalu manajemen.
 *
 * Eskalasi selalu punya akhir yang dapat ditunjuk — diakui lalu diselesaikan oleh
 * orang. Ini disengaja: eskalasi yang tidak pernah ditutup akan berubah menjadi
 * kebisingan yang diabaikan, dan peringatan yang diabaikan lebih berbahaya
 * daripada tidak ada peringatan sama sekali.
 */
class BkEscalationService
{
    public function __construct(
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
    ) {
    }

    /**
     * Eskalasi otomatis dari SLA yang terlampaui.
     *
     * Idempoten: satu eskalasi per baris SLA per tingkat.
     */
    public function escalateFromSla(BkSlaEvent $event, ?Carbon $asOf = null): BkEscalation
    {
        $asOf ??= Carbon::now();
        $target = $this->nextLevel($event);

        $dedupKey = sprintf('esc:sla:%d:level:%s', $event->id, $target->value);
        $existing = BkEscalation::query()->where('dedup_key', $dedupKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $escalation = BkEscalation::query()->create([
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'case_id' => $event->case_id,
            'sla_event_id' => $event->id,
            'from_level' => $event->current_level ?? EscalationLevel::NONE,
            'to_level' => $target,
            'reason' => sprintf(
                '%s melewati tenggat %s.',
                $event->subject_type->label(),
                $event->due_at?->toDateTimeString() ?? '-',
            ),
            'triggers' => [
                'sla_status' => $event->status->value,
                'due_at' => $event->due_at?->toIso8601String(),
                'policy_code' => $event->policy_code,
                'policy_version' => $event->policy_version,
            ],
            'status' => EscalationStatus::PENDING,
            'escalated_at' => $asOf,
            'automatic' => true,
            'dedup_key' => $dedupKey,
        ]);

        $event->forceFill(['status' => SlaStatus::ESCALATED, 'current_level' => $target])->save();

        $this->notifications->notifyTeam(
            NotificationEvent::ESCALATION,
            "Eskalasi ke {$target->label()}: {$event->subject_type->label()}",
            [
                'escalation_id' => $escalation->id,
                'sla_event_id' => $event->id,
                'to_level' => $target->value,
            ],
        );

        $this->audit->record(AuditAction::SLA_ESCALATE, $escalation, null, [
            'automatic' => true,
            'to_level' => $target->value,
            'subject_type' => $event->subject_type->value,
        ]);

        return $escalation;
    }

    /**
     * Eskalasi manual oleh orang (koordinator menaikkan kasus ke manajemen).
     *
     * @param array<string, mixed> $data
     */
    public function escalate(User $actor, SlaSubjectType|string $subjectType, Model $subject, array $data): BkEscalation
    {
        $type = $subjectType instanceof SlaSubjectType ? $subjectType : SlaSubjectType::from((string) $subjectType);
        $target = EscalationLevel::from((string) ($data['to_level'] ?? EscalationLevel::COORDINATOR->value));

        if ($target === EscalationLevel::NONE) {
            throw new BkValidationException(['to_level' => ['Tujuan eskalasi harus tingkat yang lebih tinggi.']]);
        }

        $dedupKey = sprintf('esc:%s:%d:level:%s', $type->value, $subject->getKey(), $target->value);
        $existing = BkEscalation::query()->where('dedup_key', $dedupKey)->whereIn('status', ['PENDING', 'ACKNOWLEDGED'])->first();

        if ($existing !== null) {
            return $existing;
        }

        $escalation = BkEscalation::query()->create([
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
            'case_id' => $data['case_id'] ?? null,
            'sla_event_id' => $data['sla_event_id'] ?? null,
            'from_level' => EscalationLevel::NONE,
            'to_level' => $target,
            'reason' => $data['reason'] ?? 'Perlu perhatian tingkat lanjut.',
            'triggers' => $data['triggers'] ?? null,
            'status' => EscalationStatus::PENDING,
            'escalated_at' => Carbon::now(),
            'escalated_by' => $actor->id,
            'automatic' => false,
            'dedup_key' => $dedupKey,
        ]);

        $this->audit->record(AuditAction::SLA_ESCALATE, $escalation, $actor, [
            'automatic' => false,
            'to_level' => $target->value,
        ]);

        return $escalation;
    }

    public function acknowledge(User $actor, BkEscalation $escalation, ?string $note = null): BkEscalation
    {
        if ($escalation->status === EscalationStatus::RESOLVED) {
            throw new BkValidationException(['status' => ['Eskalasi ini sudah diselesaikan.']]);
        }

        $escalation->forceFill([
            'status' => EscalationStatus::ACKNOWLEDGED,
            'acknowledged_by' => $actor->id,
            'acknowledged_at' => Carbon::now(),
            'resolution_note' => $note ?? $escalation->resolution_note,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $escalation, $actor, ['acknowledged' => true]);

        return $escalation->refresh();
    }

    public function resolve(User $actor, BkEscalation $escalation, string $resolution): BkEscalation
    {
        $escalation->forceFill([
            'status' => EscalationStatus::RESOLVED,
            'resolved_at' => Carbon::now(),
            'resolution_note' => $resolution,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $escalation, $actor, ['resolved' => true]);

        return $escalation->refresh();
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return BkEscalation::query()
            ->with(['case:id,case_number,student_id', 'escalatedBy:id,name', 'acknowledgedBy:id,name'])
            ->when(($filters['status'] ?? null) === 'open', fn ($q) => $q->open())
            ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'open' ? $q : $q->where('status', $status))
            ->when($filters['to_level'] ?? null, fn ($q, $level) => $q->where('to_level', $level))
            ->when($filters['subject_type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type))
            ->orderByDesc('escalated_at')
            ->paginate($perPage);
    }

    protected function nextLevel(BkSlaEvent $event): EscalationLevel
    {
        $current = $event->current_level ?? EscalationLevel::NONE;

        return match ($current) {
            EscalationLevel::NONE, EscalationLevel::NONE => EscalationLevel::COORDINATOR,
            EscalationLevel::COORDINATOR => $event->subject_type->escalatesToManagement()
                ? EscalationLevel::MANAGEMENT
                : EscalationLevel::MANAGEMENT,
            default => EscalationLevel::MANAGEMENT,
        };
    }
}
