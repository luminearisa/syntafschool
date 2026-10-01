<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\FollowUpStatus;
use App\Modules\Bk\Domain\Enums\FollowUpType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkFollowUp;
use App\Modules\Bk\Models\BkIntervention;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tindak lanjut — pendorong ritme pemantauan.
 *
 * Tindak lanjut punya tenggat, dapat dijadwalkan ulang, dan ditandai terlambat
 * oleh perintah terjadwal. Penandaan itu disimpan sebagai kolom (`is_overdue`)
 * agar papan pemantauan tetap cepat dan konsisten antara widget dan laporan.
 */
class BkFollowUpService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = BkFollowUp::query()->with(['case:id,case_number,student_id', 'assignedTo:id,name']);

        $this->access->scopeFollowUps($query, $actor);

        return $query
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['case_id'] ?? null, fn ($q, $caseId) => $q->where('case_id', $caseId))
            ->when($filters['assigned_to'] ?? null, fn ($q, $id) => $q->where('assigned_to', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when(filter_var($filters['open'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->open())
            ->when(filter_var($filters['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->where('is_overdue', true)->open())
            ->when($filters['due_before'] ?? null, fn ($q, $date) => $q->whereDate('due_date', '<=', $date))
            ->orderBy('due_date')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return Collection<int, BkFollowUp> */
    public function forCase(BkCase $case): Collection
    {
        return $case->followUps()->with(['assignedTo:id,name'])->orderBy('due_date')->get();
    }

    /**
     * Buat tindak lanjut.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, BkCase $case, array $data): BkFollowUp
    {
        $this->access->assertCanViewCase($actor, $case, 'Membuat tindak lanjut');

        $intervention = isset($data['intervention_id']) ? BkIntervention::query()->find($data['intervention_id']) : null;

        $followUp = BkFollowUp::query()->create([
            'follow_up_number' => $this->numbers->next('BK_FOLLOW_UP', 'BK-F'),
            'case_id' => $case->id,
            'intervention_id' => $intervention?->id,
            'assigned_to' => $data['assigned_to'] ?? $case->assigned_counselor_id ?? $actor->id,
            'created_by' => $actor->id,
            'type' => $data['type'] ?? FollowUpType::CHECK_IN->value,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'],
            'due_time' => $data['due_time'] ?? null,
            'status' => FollowUpStatus::PENDING->value,
            // Tingkat kerahasiaan kasus dapat terbaca null (rekaman lama atau
            // relasi yang tidak memilih kolomnya). Tindak lanjut TIDAK boleh gagal
            // dibuat karena itu; bawaannya adalah bawaan kolomnya (RESTRICTED).
            'confidentiality_level' => $data['confidentiality_level']
                ?? ($case->confidentiality_level ?? \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::RESTRICTED)->value,
        ]);

        $this->timeline->record(CaseEventType::FOLLOW_UP_CREATED, $actor, $case,
            summary: sprintf('Tindak lanjut "%s" dijadwalkan pada %s.', $followUp->title, $followUp->due_date?->toDateString()),
            related: $followUp);

        $this->audit->record(AuditAction::CREATE, $followUp, $actor);

        return $followUp->refresh();
    }

    /**
     * Selesaikan tindak lanjut beserta hasilnya.
     *
     * @param  array<string, mixed>  $data
     */
    public function complete(User $actor, BkFollowUp $followUp, array $data = []): BkFollowUp
    {
        if (! $this->access->canViewFollowUp($actor, $followUp)) {
            throw new BkValidationException(['follow_up' => ['Anda tidak berwenang menyelesaikan tindak lanjut ini.']]);
        }

        if ($followUp->status === FollowUpStatus::COMPLETED) {
            throw new BkValidationException(['status' => ['Tindak lanjut ini sudah selesai.']]);
        }

        $from = $followUp->status->value;

        $followUp->forceFill([
            'status' => FollowUpStatus::COMPLETED->value,
            'completed_at' => now(),
            'completed_by' => $actor->id,
            'outcome' => $data['outcome'] ?? $followUp->outcome,
            'next_action' => $data['next_action'] ?? $followUp->next_action,
            'is_overdue' => false,
        ])->save();

        if ($followUp->case !== null) {
            $this->timeline->record(CaseEventType::FOLLOW_UP_COMPLETED, $actor, $followUp->case,
                summary: 'Tindak lanjut "'.$followUp->title.'" selesai.', related: $followUp,
                statusFrom: $from, statusTo: FollowUpStatus::COMPLETED->value);
        }

        $this->audit->record(AuditAction::UPDATE, $followUp->refresh(), $actor, [
            'changes' => ['status' => ['from' => $from, 'to' => FollowUpStatus::COMPLETED->value]],
        ]);

        return $followUp->refresh();
    }

    /** Jadwalkan ulang tindak lanjut (dengan jejak berapa kali ditunda). */
    public function reschedule(User $actor, BkFollowUp $followUp, string $newDate, ?string $reason = null): BkFollowUp
    {
        if (! $this->access->canViewFollowUp($actor, $followUp)) {
            throw new BkValidationException(['follow_up' => ['Anda tidak berwenang menjadwalkan ulang tindak lanjut ini.']]);
        }

        $before = $followUp->due_date?->toDateString();

        $followUp->forceFill([
            'status' => FollowUpStatus::RESCHEDULED->value,
            'due_date' => $newDate,
            'rescheduled_to' => $newDate,
            'reschedule_count' => (int) $followUp->reschedule_count + 1,
            'is_overdue' => false,
        ])->save();

        if ($followUp->case !== null) {
            $this->timeline->record(CaseEventType::FOLLOW_UP_CREATED, $actor, $followUp->case,
                summary: sprintf('Tindak lanjut "%s" dijadwalkan ulang ke %s%s.', $followUp->title, $newDate, $reason ? ' ('.$reason.')' : ''),
                related: $followUp, statusFrom: $before, statusTo: $newDate);
        }

        $this->audit->record(AuditAction::UPDATE, $followUp->refresh(), $actor, [
            'reason' => $reason,
            'changes' => ['due_date' => ['from' => $before, 'to' => $newDate]],
        ]);

        return $followUp->refresh();
    }

    public function miss(User $actor, BkFollowUp $followUp, ?string $reason = null): BkFollowUp
    {
        $followUp->forceFill(['status' => FollowUpStatus::MISSED->value, 'is_overdue' => true])->save();

        $this->audit->record(AuditAction::UPDATE, $followUp->refresh(), $actor, ['reason' => $reason]);

        return $followUp->refresh();
    }

    /**
     * Tandai tindak lanjut yang melewati tenggat (perintah terjadwal).
     *
     * @return array{flagged: int, notified: int}
     */
    public function flagOverdue(?string $today = null): array
    {
        $today = $today ?? now()->toDateString();

        $rows = BkFollowUp::query()
            ->with(['case:id,case_number'])
            ->open()
            ->whereDate('due_date', '<', $today)
            ->get();

        $notified = 0;

        foreach ($rows as $followUp) {
            $wasOverdue = (bool) $followUp->is_overdue;

            $followUp->forceFill(['status' => FollowUpStatus::MISSED->value, 'is_overdue' => true])->save();

            if (! $wasOverdue) {
                $this->notifications->notifyFollowUp($followUp, true);
                $notified++;
            }
        }

        return ['flagged' => $rows->count(), 'notified' => $notified];
    }

    /** Kirim pengingat untuk tindak lanjut yang jatuh tempo hari ini. */
    public function remindDue(?string $today = null): int
    {
        $today = $today ?? now()->toDateString();

        $rows = BkFollowUp::query()
            ->with(['case:id,case_number'])
            ->open()
            ->whereDate('due_date', $today)
            ->whereNull('reminder_sent_at')
            ->get();

        foreach ($rows as $followUp) {
            $this->notifications->notifyFollowUp($followUp, false);
            $followUp->forceFill(['reminder_sent_at' => now()])->save();
        }

        return $rows->count();
    }
}
