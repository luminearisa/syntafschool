<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\DevelopmentActivityStatus;
use App\Modules\Bk\Domain\Enums\DevelopmentDomain;
use App\Modules\Bk\Domain\Enums\DevelopmentGoalStatus;
use App\Modules\Bk\Domain\Enums\DevelopmentReviewType;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\PlanStatus;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkDevelopmentActivity;
use App\Modules\Bk\Models\BkDevelopmentGoal;
use App\Modules\Bk\Models\BkDevelopmentPlan;
use App\Modules\Bk\Models\BkDevelopmentReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Rencana pengembangan siswa.
 *
 * Berbeda dari kasus: pengembangan tidak menuntut adanya masalah. Karena itu
 * rencana boleh dibuat untuk siswa mana pun, dan **tidak** mensyaratkan kasus.
 *
 * Aturan yang dijaga di sini:
 *   - setiap sasaran membawa baseline, target, dan cara mengukur;
 *   - baseline wajib ada sebelum peninjauan pertama, supaya "kemajuan" punya titik
 *     awal pembanding;
 *   - nomor peninjauan berurutan dan unik, sehingga riwayat kemajuan tidak dapat
 *     ditulis mundur atau ditimpa.
 */
class BkDevelopmentPlanService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = BkDevelopmentPlan::query()
            ->with(['student:id,full_name,nis', 'owner:id,name'])
            ->withCount(['goals', 'activities', 'reviews']);

        $visible = $this->access->visibleStudentIds($actor);

        // Koleksi kosong berarti pemakainya adalah staf BK yang tidak dibatasi
        // cakupan siswa; koleksi berisi berarti batasnya memang daftar itu.
        if ($visible->isNotEmpty()) {
            $query->whereIn('student_id', $visible);
        }

        return $query
            ->when($filters['student_id'] ?? null, fn ($q, $id) => $q->where('student_id', (int) $id))
            ->when($filters['domain'] ?? null, fn ($q, $domain) => $q->where('domain', $domain))
            ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'active' ? $q->active() : $q->where('status', $status))
            ->when(filter_var($filters['review_due'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->reviewDue())
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): BkDevelopmentPlan
    {
        $studentId = (int) $data['student_id'];
        $this->access->assertCanViewSupportProfile($actor, $studentId);

        return DB::transaction(function () use ($actor, $data, $studentId): BkDevelopmentPlan {
            $plan = BkDevelopmentPlan::query()->create([
                'plan_number' => $this->numbers->next('BK_DEVELOPMENT_PLAN', 'BK-DP'),
                'student_id' => $studentId,
                'case_id' => $data['case_id'] ?? null,
                'domain' => DevelopmentDomain::from((string) $data['domain']),
                'title' => $data['title'],
                'rationale' => $data['rationale'] ?? null,
                'baseline_summary' => $data['baseline_summary'] ?? null,
                'status' => PlanStatus::from((string) ($data['status'] ?? PlanStatus::DRAFT->value)),
                'owner_user_id' => $data['owner_user_id'] ?? $actor->id,
                'start_date' => $data['start_date'] ?? now()->toDateString(),
                'target_end_date' => $data['target_end_date'] ?? null,
                'review_interval_days' => (int) ($data['review_interval_days'] ?? 30),
                'next_review_date' => $data['next_review_date']
                    ?? now()->addDays((int) ($data['review_interval_days'] ?? 30))->toDateString(),
                'confidentiality_level' => $data['confidentiality_level'] ?? \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::STAFF_ONLY,
                'created_by' => $actor->id,
            ]);

            foreach (($data['goals'] ?? []) as $goal) {
                $this->addGoal($actor, $plan, (array) $goal);
            }

            $this->audit->record(AuditAction::DEVELOPMENT_UPDATE, $plan, $actor, [
                'domain' => $plan->domain->value,
                'goals' => $plan->goals()->count(),
            ]);

            return $plan->refresh()->load(['goals', 'student:id,full_name']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, BkDevelopmentPlan $plan, array $data): BkDevelopmentPlan
    {
        $before = $plan->only(['title', 'rationale', 'status', 'target_end_date', 'next_review_date']);

        $plan->fill([
            'title' => $data['title'] ?? $plan->title,
            'rationale' => $data['rationale'] ?? $plan->rationale,
            'baseline_summary' => $data['baseline_summary'] ?? $plan->baseline_summary,
            'status' => isset($data['status']) ? PlanStatus::from((string) $data['status']) : $plan->status,
            'owner_user_id' => $data['owner_user_id'] ?? $plan->owner_user_id,
            'target_end_date' => $data['target_end_date'] ?? $plan->target_end_date,
            'review_interval_days' => (int) ($data['review_interval_days'] ?? $plan->review_interval_days),
            'next_review_date' => $data['next_review_date'] ?? $plan->next_review_date,
            'achievement_note' => $data['achievement_note'] ?? $plan->achievement_note,
            'updated_by' => $actor->id,
        ]);

        if ($plan->status === PlanStatus::COMPLETED && $plan->completed_at === null) {
            $plan->completed_at = now();
        }

        $plan->save();

        $this->audit->record(AuditAction::DEVELOPMENT_UPDATE, $plan, $actor, [
            'changed' => array_keys($this->audit->diff($before, $plan->only(array_keys($before)))),
        ]);

        return $plan->refresh();
    }

    /**
     * Menambah sasaran.
     *
     * Baseline, target, dan pengukuran wajib: tanpa ketiganya, sasaran berubah
     * menjadi harapan yang tidak dapat diperiksa kemajuannya.
     *
     * @param array<string, mixed> $data
     */
    public function addGoal(User $actor, BkDevelopmentPlan $plan, array $data): BkDevelopmentGoal
    {
        foreach (['goal', 'measurement'] as $required) {
            if (empty($data[$required])) {
                throw new BkValidationException([$required => ["Kolom {$required} wajib diisi pada setiap sasaran."]]);
            }
        }

        $goal = BkDevelopmentGoal::query()->create([
            'plan_id' => $plan->id,
            'goal' => $data['goal'],
            'baseline_value' => $data['baseline_value'] ?? null,
            'target_value' => $data['target_value'] ?? null,
            'measurement' => $data['measurement'],
            'measurement_unit' => $data['measurement_unit'] ?? null,
            'current_value' => $data['baseline_value'] ?? null,
            'progress' => 0,
            'status' => DevelopmentGoalStatus::NOT_STARTED,
            'owner_user_id' => $data['owner_user_id'] ?? $plan->owner_user_id,
            'start_date' => $data['start_date'] ?? $plan->start_date,
            'target_date' => $data['target_date'] ?? $plan->target_end_date,
            'weight' => (int) ($data['weight'] ?? 1),
            'created_by' => $actor->id,
        ]);

        $this->audit->record(AuditAction::DEVELOPMENT_UPDATE, $goal, $actor, ['created' => true, 'plan_id' => $plan->id]);

        return $goal;
    }

    /**
     * Pencatatan baseline.
     *
     * Baseline hanya boleh sekali per sasaran: kalau boleh diulang, angka awal
     * dapat digeser agar kemajuan tampak lebih baik.
     *
     * @param array<string, mixed> $data
     */
    public function recordBaseline(User $actor, BkDevelopmentGoal $goal, array $data): BkDevelopmentReview
    {
        $exists = $goal->reviews()->where('review_type', DevelopmentReviewType::BASELINE->value)->exists();

        if ($exists) {
            throw new BkValidationException(['review_type' => ['Baseline sasaran ini sudah pernah dicatat.']]);
        }

        return DB::transaction(function () use ($actor, $goal, $data): BkDevelopmentReview {
            $review = BkDevelopmentReview::query()->create([
                'plan_id' => $goal->plan_id,
                'goal_id' => $goal->id,
                'review_number' => 1,
                'review_type' => DevelopmentReviewType::BASELINE,
                'reviewed_on' => $data['reviewed_on'] ?? now()->toDateString(),
                'reviewer_user_id' => $actor->id,
                'progress_value' => $data['progress_value'] ?? $goal->baseline_value,
                'progress_percent' => 0,
                'progress_note' => $data['progress_note'] ?? 'Titik awal pengukuran.',
                'status_before' => $goal->status->value,
                'status_after' => DevelopmentGoalStatus::IN_PROGRESS->value,
                'created_by' => $actor->id,
            ]);

            $goal->forceFill([
                'baseline_value' => $data['progress_value'] ?? $goal->baseline_value,
                'current_value' => $data['progress_value'] ?? $goal->current_value,
                'status' => DevelopmentGoalStatus::IN_PROGRESS,
            ])->save();

            $this->audit->record(AuditAction::DEVELOPMENT_UPDATE, $goal, $actor, ['baseline_recorded' => true]);

            return $review;
        });
    }

    /**
     * Peninjauan berkala: mencatat nilai terukur, menghitung kemajuan, dan
     * memperbarui status sasaran.
     *
     * Kemajuan dihitung dari jarak baseline ke target, bukan dari perasaan —
     * sehingga dua orang yang melihat angka yang sama akan menyimpulkan hal sama.
     *
     * @param array<string, mixed> $data
     */
    public function review(User $actor, BkDevelopmentGoal $goal, array $data): BkDevelopmentReview
    {
        if (! $goal->reviews()->where('review_type', DevelopmentReviewType::BASELINE->value)->exists()) {
            throw new BkValidationException([
                'review_type' => ['Catat baseline sasaran sebelum meninjau kemajuannya.'],
            ]);
        }

        $status = DevelopmentGoalStatus::from((string) ($data['status'] ?? DevelopmentGoalStatus::IN_PROGRESS->value));

        if (! in_array($status, $goal->status->allowedTransitions(), true) && $status !== $goal->status) {
            throw new BkValidationException([
                'status' => ["Status sasaran tidak dapat berpindah dari {$goal->status->label()} ke {$status->label()}."],
            ]);
        }

        return DB::transaction(function () use ($actor, $goal, $data, $status): BkDevelopmentReview {
            $nextNumber = (int) $goal->reviews()->max('review_number') + 1;
            $value = $data['progress_value'] ?? $goal->current_value;
            $progress = $data['progress_percent'] ?? $this->computeProgress($goal, $value);

            $reviewType = $status === DevelopmentGoalStatus::ACHIEVED
                ? DevelopmentReviewType::FINAL
                : DevelopmentReviewType::REVIEW;

            $review = BkDevelopmentReview::query()->create([
                'plan_id' => $goal->plan_id,
                'goal_id' => $goal->id,
                'review_number' => $nextNumber,
                'review_type' => $reviewType,
                'reviewed_on' => $data['reviewed_on'] ?? now()->toDateString(),
                'reviewer_user_id' => $actor->id,
                'progress_value' => $value,
                'progress_percent' => $progress,
                'rating' => $data['rating'] ?? null,
                'progress_note' => $data['progress_note'] ?? null,
                'status_before' => $goal->status->value,
                'status_after' => $status->value,
                'next_review_date' => $data['next_review_date'] ?? null,
                'created_by' => $actor->id,
            ]);

            $goal->forceFill([
                'current_value' => $value,
                'progress' => max(0, min(100, (int) $progress)),
                'status' => $status,
                'achieved_at' => $status === DevelopmentGoalStatus::ACHIEVED ? now() : $goal->achieved_at,
                'updated_by' => $actor->id,
            ])->save();

            $this->refreshPlanProgress($actor, $goal->plan, $data['next_review_date'] ?? null);

            $this->audit->record(AuditAction::DEVELOPMENT_UPDATE, $goal, $actor, [
                'review_number' => $nextNumber,
                'progress_percent' => $progress,
                'status' => $status->value,
            ]);

            return $review;
        });
    }

    /** @param array<string, mixed> $data */
    public function addActivity(User $actor, BkDevelopmentPlan $plan, array $data): BkDevelopmentActivity
    {
        if (empty($data['title'])) {
            throw new BkValidationException(['title' => ['Kegiatan pengembangan wajib memiliki judul.']]);
        }

        $activity = BkDevelopmentActivity::query()->create([
            'plan_id' => $plan->id,
            'goal_id' => $data['goal_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'activity_type' => $data['activity_type'] ?? 'OTHER',
            'scheduled_date' => $data['scheduled_date'] ?? null,
            'status' => DevelopmentActivityStatus::from((string) ($data['status'] ?? DevelopmentActivityStatus::PLANNED->value)),
            'facilitator_user_id' => $data['facilitator_user_id'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit->record(AuditAction::DEVELOPMENT_UPDATE, $activity, $actor, ['created' => true, 'plan_id' => $plan->id]);

        return $activity;
    }

    /** @param array<string, mixed> $data */
    public function completeActivity(User $actor, BkDevelopmentActivity $activity, array $data = []): BkDevelopmentActivity
    {
        $activity->forceFill([
            'status' => DevelopmentActivityStatus::COMPLETED,
            'completed_date' => $data['completed_date'] ?? now()->toDateString(),
            'evidence' => $data['evidence'] ?? $activity->evidence,
            'document_id' => $data['document_id'] ?? $activity->document_id,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::DEVELOPMENT_UPDATE, $activity, $actor, ['completed' => true]);

        return $activity->refresh();
    }

    /**
     * Kemajuan sasaran dihitung dari posisi nilai terhadap baseline dan target.
     * Arah dibaca dari selisih target-baseline, sehingga sasaran yang menurun
     * (mis. mengurangi keterlambatan) tetap terhitung benar.
     */
    public function computeProgress(BkDevelopmentGoal $goal, float|int|string|null $value): int
    {
        $baseline = $goal->baseline_value !== null ? (float) $goal->baseline_value : null;
        $target = $goal->target_value !== null ? (float) $goal->target_value : null;
        $current = is_numeric($value) ? (float) $value : null;

        if ($baseline === null || $target === null || $current === null || $baseline === $target) {
            return (int) $goal->progress;
        }

        $progress = (($current - $baseline) / ($target - $baseline)) * 100;

        return (int) round(max(0, min(100, $progress)));
    }

    /** Ringkasan rencana untuk satu siswa (dipakai tab pengembangan pada profil siswa). */
    public function summaryForStudent(int $studentId): array
    {
        $plans = BkDevelopmentPlan::query()->where('student_id', $studentId)->with(['goals', 'reviews'])->get();

        return [
            'total' => $plans->count(),
            'active' => $plans->whereIn('status', [PlanStatus::ACTIVE, PlanStatus::REVIEW])->count(),
            'completed' => $plans->where('status', PlanStatus::COMPLETED)->count(),
            'review_due' => $plans->filter(fn (BkDevelopmentPlan $plan): bool => $plan->next_review_date !== null && $plan->next_review_date->isPast())->count(),
            'domains' => $plans->groupBy(fn (BkDevelopmentPlan $plan): string => $plan->domain->value)
                ->map(fn ($group, string $domain): array => ['domain' => $domain, 'total' => $group->count()])
                ->values()
                ->all(),
            'average_goal_progress' => round((float) $plans->flatMap->goals->avg('progress'), 1),
        ];
    }

    /** Memperbarui kemajuan rencana dari sasaran-sasarannya (agregat, bukan salinan). */
    protected function refreshPlanProgress(User $actor, ?BkDevelopmentPlan $plan, ?string $nextReviewDate): void
    {
        if ($plan === null) {
            return;
        }

        $plan->loadMissing('goals');

        $allAchieved = $plan->goals->isNotEmpty()
            && $plan->goals->every(fn (BkDevelopmentGoal $goal): bool => $goal->status === DevelopmentGoalStatus::ACHIEVED);

        $plan->forceFill([
            'status' => $allAchieved ? PlanStatus::COMPLETED : PlanStatus::REVIEW,
            'completed_at' => $allAchieved ? ($plan->completed_at ?? now()) : null,
            'next_review_date' => $nextReviewDate
                ?? now()->addDays((int) $plan->review_interval_days)->toDateString(),
            'updated_by' => $actor->id,
        ])->save();

        if ($allAchieved) {
            $this->notifications->notifyTeam(
                NotificationEvent::DEVELOPMENT_REVIEW_DUE,
                "Rencana pengembangan {$plan->plan_number} mencapai seluruh sasarannya.",
                ['plan_id' => $plan->id, 'student_id' => $plan->student_id],
            );
        }
    }
}
