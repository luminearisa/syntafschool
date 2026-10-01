<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Domain\Contracts\BusinessCalendarInterface;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Enums\SlaStatus;
use App\Modules\Workflow\Domain\Support\WorkflowGraph;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowSlaRecord;
use App\Modules\Workflow\Models\WorkflowStepInstance;

/**
 * Perhitungan SLA per step (§41, §42, §69).
 *
 * Ambang disimpan **di baris SLA**, bukan dihitung ulang dari konfigurasi saat
 * pembacaan: bila kelak admin menaikkan SLA dari 48 jam ke 72 jam, riwayat
 * lama tetap dapat menjelaskan kenapa step itu dulu dinyatakan terlambat.
 */
class SlaService
{
    public function __construct(
        private readonly BusinessCalendarInterface $calendar,
        private readonly WorkflowAuditService $audit,
    ) {
    }

    /**
     * Mulai pencatatan SLA untuk sebuah step.
     *
     * @param  array<string, mixed>  $node
     */
    public function start(WorkflowInstance $instance, WorkflowStepInstance $step, array $node, ?string $calendarCode = null): WorkflowSlaRecord
    {
        $minutes = (int) ($node['sla_minutes'] ?? config('workflow.sla.default_minutes', 0) ?? 0);

        if ($minutes <= 0) {
            $minutes = 0;
        }

        $startedAt = now();
        $dueAt = $minutes > 0
            ? $this->calendar->addWorkingMinutes($startedAt, $minutes, $calendarCode)
            : null;

        $config = (array) config('workflow.sla', []);
        $reminderPercent = (int) ($config['reminder_percent'] ?? 50);
        $dueSoonPercent = (int) ($config['due_soon_percent'] ?? 80);

        $record = WorkflowSlaRecord::query()->updateOrCreate(
            ['step_instance_id' => $step->id],
            [
                'instance_id' => $instance->id,
                'calendar_id' => null,
                'sla_minutes' => $minutes,
                'started_at' => $startedAt,
                'due_at' => $dueAt ?? $startedAt,
                'remind_at' => $dueAt === null ? null : $startedAt->copy()->addMinutes((int) floor($minutes * $reminderPercent / 100)),
                'due_soon_at' => $dueAt === null ? null : $startedAt->copy()->addMinutes((int) floor($minutes * $dueSoonPercent / 100)),
                'escalate_at' => $dueAt,
                'status' => SlaStatus::ON_TIME->value,
                'max_escalations' => (int) (($node['escalation']['max_levels'] ?? null) ?? ($config['escalation']['max_levels'] ?? 3)),
            ],
        );

        $step->forceFill([
            'sla_minutes' => $minutes > 0 ? $minutes : null,
            'sla_status' => SlaStatus::ON_TIME->value,
            'due_at' => $dueAt,
        ])->save();

        if ($dueAt !== null) {
            $instance->forceFill(['due_at' => $instance->due_at ?? $dueAt])->save();
        }

        return $record;
    }

    /** Tandai SLA selesai (step selesai dalam batas waktu atau tidak). */
    public function complete(WorkflowStepInstance $step, ?User $actor = null): void
    {
        $record = WorkflowSlaRecord::query()->where('step_instance_id', $step->id)->first();

        if ($record === null) {
            return;
        }

        $record->forceFill([
            'status' => SlaStatus::COMPLETED,
            'metadata' => array_merge((array) $record->metadata, [
                'completed_at' => now()->toIso8601String(),
                'sla_minutes' => $record->sla_minutes,
                'actual_minutes' => $record->sla_minutes > 0
                    ? $this->calendar->minutesBetween($record->started_at, now(), null)
                    : (int) $record->started_at->diffInMinutes(now()),
                'breached' => $record->due_at !== null && $record->due_at->isPast(),
            ]),
        ])->save();

        $step->forceFill(['sla_status' => SlaStatus::COMPLETED->value])->save();

        $this->audit->record(
            AuditAction::SLA_COMPLETED,
            $step->instance,
            $record,
            after: ['status' => SlaStatus::COMPLETED->value],
            actor: $actor,
            extra: ['step_instance_id' => $step->id],
        );
    }

    /** SLA dibatalkan karena request dibatalkan/dikembalikan. */
    public function cancel(WorkflowStepInstance $step): void
    {
        $record = WorkflowSlaRecord::query()->where('step_instance_id', $step->id)->first();

        if ($record === null) {
            return;
        }

        $record->forceFill(['status' => SlaStatus::CANCELLED])->save();
        $step->forceFill(['sla_status' => SlaStatus::CANCELLED->value])->save();
    }

    /**
     * Segarkan status SLA berdasarkan waktu sekarang (idempoten).
     *
     * @return SlaStatus status terbaru
     */
    public function refresh(WorkflowSlaRecord $record): SlaStatus
    {
        if (! $record->status->isOpen()) {
            return $record->status;
        }

        $now = now();
        $status = $record->status;

        if ($record->due_at !== null && $now->greaterThanOrEqualTo($record->due_at)) {
            $status = $record->escalated_at !== null ? SlaStatus::ESCALATED : SlaStatus::OVERDUE;

            if ($record->overdue_at === null) {
                $record->overdue_at = $now;
            }
        } elseif ($record->due_soon_at !== null && $now->greaterThanOrEqualTo($record->due_soon_at)) {
            $status = SlaStatus::DUE_SOON;
        }

        $record->forceFill(['status' => $status])->save();
        $record->step?->forceFill(['sla_status' => $status->value])->save();

        return $status;
    }

    /** Ringkasan SLA untuk dasbor (§52, §53). */
    public function summary(?int $days = 30): array
    {
        $since = now()->subDays($days ?? 30);

        $records = WorkflowSlaRecord::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('due_at')
            ->get();

        $completed = $records->where('status', SlaStatus::COMPLETED);
        $breached = $completed->filter(fn (WorkflowSlaRecord $r) => ($r->metadata['breached'] ?? false) === true);

        $avgMinutes = $completed->avg(fn (WorkflowSlaRecord $r) => (int) ($r->metadata['actual_minutes'] ?? 0));

        return [
            'total' => $records->count(),
            'on_time' => $completed->count() - $breached->count(),
            'overdue_open' => $records->whereIn('status', [SlaStatus::OVERDUE, SlaStatus::ESCALATED])->count(),
            'due_soon_open' => $records->where('status', SlaStatus::DUE_SOON)->count(),
            'compliance_percent' => $completed->count() === 0
                ? null
                : round((($completed->count() - $breached->count()) / max(1, $completed->count())) * 100, 1),
            'average_minutes' => $avgMinutes === null ? null : (int) round($avgMinutes),
        ];
    }

    /** SLA record yang perlu disapu perintah terjadwal. */
    public function recordsToSweep(int $limit = 500): array
    {
        return WorkflowSlaRecord::query()
            ->whereIn('status', [SlaStatus::ON_TIME->value, SlaStatus::DUE_SOON->value, SlaStatus::OVERDUE->value])
            ->whereHas('step', fn ($q) => $q->whereIn('status', ['ACTIVE', 'WAITING_APPROVAL', 'WAITING_REVIEW', 'WAITING_FOR_APPROVER']))
            ->with(['step', 'instance'])
            ->orderBy('due_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /** Ringkasan langkah SLA sebuah graf (dipakai simulator & dokumentasi). */
    public function slaByNode(WorkflowGraph $graph): array
    {
        $rows = [];

        foreach ($graph->nodes() as $node) {
            if (($node['type'] ?? null) === 'START' || ($node['type'] ?? null) === 'END') {
                continue;
            }

            $rows[] = [
                'key' => $node['key'],
                'name' => $node['name'] ?? $node['key'],
                'sla_minutes' => $node['sla_minutes'] ?? null,
                'humanized' => $this->humanize($node['sla_minutes'] ?? null),
            ];
        }

        return $rows;
    }

    public function humanize(?int $minutes): ?string
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        if ($minutes % 1440 === 0) {
            return ($minutes / 1440).' hari kerja';
        }

        if ($minutes % 60 === 0) {
            return ($minutes / 60).' jam kerja';
        }

        return $minutes.' menit';
    }
}
