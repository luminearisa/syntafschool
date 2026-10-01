<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\GoalStatus;
use App\Modules\Bk\Domain\Enums\PlanStatus;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCounselingPlan;
use App\Modules\Bk\Models\BkGoal;
use Illuminate\Support\Facades\DB;

/**
 * Rencana konseling & sasaran.
 *
 * Alurnya mengikuti §14: masalah → sasaran → indikator keberhasilan → intervensi
 * → penanggung jawab → tenggat → tinjauan. Sasaran tanpa indikator ditolak,
 * karena rencana yang tidak dapat diukur tidak dapat ditinjau.
 */
class BkPlanService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkAccessService $access,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, BkCase $case, array $data): BkCounselingPlan
    {
        $this->access->assertCanViewCase($actor, $case, 'Membuat rencana konseling');

        return DB::transaction(function () use ($actor, $case, $data): BkCounselingPlan {
            $plan = BkCounselingPlan::query()->create([
                'plan_number' => $this->numbers->next('BK_PLAN', 'BK-P'),
                'case_id' => $case->id,
                'title' => $data['title'],
                'problem_statement' => $data['problem_statement'],
                'counselor_id' => $data['counselor_id'] ?? $case->assigned_counselor_id ?? $actor->id,
                'started_on' => $data['started_on'] ?? now()->toDateString(),
                'target_end_on' => $data['target_end_on'] ?? null,
                'status' => $data['status'] ?? PlanStatus::ACTIVE->value,
                'confidentiality_level' => $data['confidentiality_level'] ?? $case->confidentiality_level->value,
                'created_by' => $actor->id,
            ]);

            foreach ((array) ($data['goals'] ?? []) as $index => $goal) {
                $this->createGoal($actor, $plan, $goal, $index + 1);
            }

            $this->timeline->record(CaseEventType::PLAN_CREATED, $actor, $case,
                summary: 'Rencana konseling "'.$plan->title.'" dibuat.', related: $plan);

            $this->audit->record(AuditAction::CREATE, $plan, $actor);

            return $plan->load('goals');
        });
    }

    /** @param array<string, mixed> $data */
    public function createGoal(User $actor, BkCounselingPlan $plan, array $data, int $sortOrder = 1): BkGoal
    {
        if (trim((string) ($data['success_indicator'] ?? '')) === '') {
            throw new BkValidationException(['goals' => ['Setiap sasaran wajib memiliki indikator keberhasilan.']]);
        }

        $goal = BkGoal::query()->create([
            'plan_id' => $plan->id,
            'case_id' => $plan->case_id,
            'goal' => $data['goal'],
            'objective' => $data['objective'] ?? null,
            'success_indicator' => $data['success_indicator'],
            'intervention_approach' => $data['intervention_approach'] ?? null,
            'responsible_user_id' => $data['responsible_user_id'] ?? $plan->counselor_id,
            'timeline_start' => $data['timeline_start'] ?? now()->toDateString(),
            'timeline_end' => $data['timeline_end'] ?? null,
            'status' => $data['status'] ?? GoalStatus::PENDING->value,
            'sort_order' => $data['sort_order'] ?? $sortOrder,
        ]);

        if ($plan->case !== null) {
            $this->timeline->record(CaseEventType::GOAL_UPDATED, $actor, $plan->case,
                summary: 'Sasaran baru ditambahkan pada rencana '.$plan->plan_number.'.', related: $goal);
        }

        return $goal;
    }

    /**
     * Perbarui kemajuan sasaran.
     *
     * `progress` 100 otomatis menandai sasaran tercapai; sebaliknya status
     * tercapai tidak dapat dipilih dengan kemajuan di bawah 100.
     */
    public function updateGoal(User $actor, BkGoal $goal, array $data): BkGoal
    {
        if ($goal->case !== null) {
            $this->access->assertCanViewCase($actor, $goal->case, 'Memperbarui sasaran');
        }

        $progress = isset($data['progress']) ? max(0, min(100, (int) $data['progress'])) : (int) $goal->progress;
        $status = $data['status'] ?? ($progress >= 100 ? GoalStatus::ACHIEVED->value : $goal->status->value);

        if ($status === GoalStatus::ACHIEVED->value && $progress < 100) {
            throw new BkValidationException(['progress' => ['Sasaran tidak dapat dinyatakan tercapai dengan kemajuan di bawah 100%.']]);
        }

        $before = ['progress' => (int) $goal->progress, 'status' => $goal->status->value];

        $goal->forceFill([
            'progress' => $progress,
            'status' => $status,
            'review_notes' => $data['review_notes'] ?? $goal->review_notes,
            'objective' => $data['objective'] ?? $goal->objective,
            'timeline_end' => $data['timeline_end'] ?? $goal->timeline_end?->toDateString(),
            'reviewed_at' => in_array($status, [GoalStatus::ACHIEVED->value, GoalStatus::NOT_ACHIEVED->value, GoalStatus::PARTIALLY_ACHIEVED->value], true) ? now() : $goal->reviewed_at,
        ])->save();

        if ($goal->case !== null) {
            $this->timeline->record(CaseEventType::GOAL_UPDATED, $actor, $goal->case,
                summary: 'Kemajuan sasaran diperbarui menjadi '.$progress.'%.', related: $goal,
                payload: ['before' => $before, 'after' => ['progress' => $progress, 'status' => $status]]);
        }

        $this->audit->record(AuditAction::UPDATE, $goal->refresh(), $actor, [
            'changes' => ['progress' => ['from' => $before['progress'], 'to' => $progress]],
        ]);

        return $goal->refresh();
    }

    public function review(User $actor, BkCounselingPlan $plan, ?string $notes = null, ?string $status = null): BkCounselingPlan
    {
        if ($plan->case !== null) {
            $this->access->assertCanViewCase($actor, $plan->case, 'Meninjau rencana');
        }

        $plan->forceFill([
            'status' => $status ?? ($plan->goals()->whereIn('status', ['PENDING', 'IN_PROGRESS'])->exists() ? PlanStatus::REVIEW->value : PlanStatus::COMPLETED->value),
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $plan->refresh(), $actor, ['reason' => $notes]);

        return $plan->refresh();
    }
}
