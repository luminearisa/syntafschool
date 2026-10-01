<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\FollowUpType;
use App\Modules\Bk\Domain\Enums\InterventionStatus;
use App\Modules\Bk\Domain\Enums\InterventionType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkIntervention;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Intervensi — tindakan nyata setelah rencana disusun.
 *
 * Setiap intervensi punya pemilik, tenggat, dan hasil. Bila ditandai perlu
 * tindak lanjut, penyelesaiannya otomatis melahirkan satu tindak lanjut
 * terjadwal — supaya "sudah dilakukan" tidak berhenti sebagai catatan.
 */
class BkInterventionService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkFollowUpService $followUps,
        private readonly BkAccessService $access,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = BkIntervention::query()->with(['case:id,case_number,student_id', 'owner:id,name']);

        if (! $this->access->isBkStaff($actor)) {
            $query->whereHas('case', fn ($q) => $q->whereIn('student_id', $this->access->visibleStudentIds($actor)));
        } elseif (! $this->access->canMonitorWorkload($actor)) {
            $query->where(function ($q) use ($actor): void {
                $q->where('owner_user_id', $actor->id)
                    ->orWhereHas('case', fn ($c) => $c->where('assigned_counselor_id', $actor->id));
            });
        }

        return $query
            ->when($filters['case_id'] ?? null, fn ($q, $caseId) => $q->where('case_id', $caseId))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(filter_var($filters['open'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->open())
            ->when($filters['owner_user_id'] ?? null, fn ($q, $id) => $q->where('owner_user_id', $id))
            ->orderByRaw("CASE status WHEN 'IN_PROGRESS' THEN 1 WHEN 'PLANNED' THEN 2 ELSE 3 END")
            ->orderBy('target_date')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, BkCase $case, array $data): BkIntervention
    {
        $this->access->assertCanViewCase($actor, $case, 'Membuat intervensi');

        $intervention = BkIntervention::query()->create([
            'intervention_number' => $this->numbers->next('BK_INTERVENTION', 'BK-I'),
            'case_id' => $case->id,
            'plan_id' => $data['plan_id'] ?? null,
            'goal_id' => $data['goal_id'] ?? null,
            'type' => $data['type'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'owner_user_id' => $data['owner_user_id'] ?? $case->assigned_counselor_id ?? $actor->id,
            'owner_role' => $data['owner_role'] ?? null,
            'owner_name' => $data['owner_name'] ?? null,
            'start_date' => $data['start_date'] ?? now()->toDateString(),
            'target_date' => $data['target_date'] ?? null,
            'status' => $data['status'] ?? InterventionStatus::PLANNED->value,
            'follow_up_required' => (bool) ($data['follow_up_required'] ?? false),
            'notes' => $data['notes'] ?? null,
            'confidentiality_level' => $data['confidentiality_level'] ?? $case->confidentiality_level->value,
            'created_by' => $actor->id,
        ]);

        $this->timeline->record(CaseEventType::INTERVENTION_CREATED, $actor, $case,
            summary: $intervention->type->label().' dimulai: '.$intervention->title, related: $intervention);

        $this->audit->record(AuditAction::CREATE, $intervention, $actor);

        return $intervention->refresh();
    }

    /**
     * Perbarui intervensi; menyelesaikannya dapat memicu tindak lanjut.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, BkIntervention $intervention, array $data): BkIntervention
    {
        if (! $this->access->canViewCase($actor, $intervention->case)) {
            throw new BkValidationException(['intervention' => ['Anda tidak memiliki akses ke intervensi ini.']]);
        }

        $before = [
            'status' => $intervention->status->value,
            'target_date' => $intervention->target_date?->toDateString(),
            'result' => $intervention->result,
        ];

        $status = $data['status'] ?? $intervention->status->value;

        $intervention->fill([
            'status' => $status,
            'title' => $data['title'] ?? $intervention->title,
            'description' => $data['description'] ?? $intervention->description,
            'owner_user_id' => $data['owner_user_id'] ?? $intervention->owner_user_id,
            'owner_name' => $data['owner_name'] ?? $intervention->owner_name,
            'target_date' => $data['target_date'] ?? $intervention->target_date?->toDateString(),
            'result' => $data['result'] ?? $intervention->result,
            'follow_up_required' => $data['follow_up_required'] ?? $intervention->follow_up_required,
            'notes' => $data['notes'] ?? $intervention->notes,
            'completed_at' => $status === InterventionStatus::COMPLETED->value ? ($intervention->completed_at ?? now()) : null,
        ]);

        $intervention->save();

        $this->timeline->record(CaseEventType::INTERVENTION_UPDATED, $actor, $intervention->case,
            summary: 'Intervensi "'.$intervention->title.'" diperbarui ('.$status.').', related: $intervention,
            statusFrom: $before['status'], statusTo: $status);

        $this->audit->record(AuditAction::UPDATE, $intervention->refresh(), $actor, [
            'changes' => ['status' => ['from' => $before['status'], 'to' => $status]],
        ]);

        // Intervensi selesai + perlu tindak lanjut → lahirkan tindak lanjut terjadwal.
        if ($status === InterventionStatus::COMPLETED->value
            && $intervention->follow_up_required
            && ($data['create_follow_up'] ?? true)) {
            $this->followUps->create($actor, $intervention->case, [
                'intervention_id' => $intervention->id,
                'type' => $data['follow_up_type'] ?? FollowUpType::PROGRESS_REVIEW->value,
                'title' => 'Tindak lanjut: '.$intervention->title,
                'description' => $intervention->result,
                'due_date' => $data['follow_up_due'] ?? now()->addDays(14)->toDateString(),
                'assigned_to' => $intervention->owner_user_id,
            ]);
        }

        return $intervention->refresh();
    }

    /** Ringkasan jenis intervensi yang paling sering dipakai (analitik). */
    public function typeBreakdown(?string $from = null, ?string $to = null): array
    {
        return BkIntervention::query()
            ->when($from, fn ($q) => $q->whereDate('start_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('start_date', '<=', $to))
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /** Label jenis intervensi yang tersedia (untuk pemilih di layar). */
    public function typeOptions(): array
    {
        return array_map(fn (InterventionType $type): array => ['value' => $type->value, 'label' => $type->label()], InterventionType::cases());
    }
}
