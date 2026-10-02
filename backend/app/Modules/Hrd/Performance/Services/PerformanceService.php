<?php

namespace App\Modules\Hrd\Performance\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Domain\Contracts\PerformanceProviderInterface;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Performance\Models\HrCalibrationEntry;
use App\Modules\Hrd\Performance\Models\HrCompetencyCatalog;
use App\Modules\Hrd\Performance\Models\HrCompetencyLevel;
use App\Modules\Hrd\Performance\Models\HrDevelopmentPlan;
use App\Modules\Hrd\Performance\Models\HrDevelopmentPlanAction;
use App\Modules\Hrd\Performance\Models\HrGoalKeyResult;
use App\Modules\Hrd\Performance\Models\HrGoalUpdate;
use App\Modules\Hrd\Performance\Models\HrPerformanceCalibration;
use App\Modules\Hrd\Performance\Models\HrPerformanceCompetencyAssessment;
use App\Modules\Hrd\Performance\Models\HrPerformanceCycle;
use App\Modules\Hrd\Performance\Models\HrPerformanceCycleParticipant;
use App\Modules\Hrd\Performance\Models\HrPerformanceFeedback;
use App\Modules\Hrd\Performance\Models\HrPerformanceGoal;
use App\Modules\Hrd\Performance\Models\HrPerformanceImprovementPlan;
use App\Modules\Hrd\Performance\Models\HrPerformanceReview;
use App\Modules\Hrd\Performance\Models\HrPipReview;
use App\Modules\Hrd\Performance\Models\HrRatingScale;
use App\Modules\Hrd\Performance\Models\HrRatingScaleLevel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kinerja: siklus → sasaran → penilaian → kalibrasi → pengembangan.
 *
 * Angka akhir pegawai selalu dapat ditelusuri: skor sasaran berasal dari
 * kemajuan key result, skor kompetensi dari penilaian per kompetensi, dan
 * kotak 9-box berasal dari kinerja × potensi yang tersimpan — bukan angka yang
 * diketik langsung ke dalam kotak.
 */
class PerformanceService implements PerformanceProviderInterface
{
    public function __construct(private readonly HrdPhase2Events $events) {}

    // ------------------------------------------------------------------ siklus

    /** @param array<string, mixed> $attributes */
    public function createCycle(array $attributes, User $actor): HrPerformanceCycle
    {
        $scale = HrRatingScale::query()->where('is_default', true)->first()
            ?? HrRatingScale::query()->where('code', (string) config('hrd_phase2.performance.default_scale', 'SCALE_1_5'))->first();

        return HrPerformanceCycle::query()->create([
            'code' => strtoupper((string) ($attributes['code'] ?? 'CYC-'.now()->format('Y'))),
            'name' => $attributes['name'] ?? 'Siklus Kinerja '.now()->year,
            'cycle_type' => strtoupper((string) ($attributes['cycle_type'] ?? 'ANNUAL')),
            'organization_id' => $attributes['organization_id'] ?? null,
            'start_date' => $attributes['start_date'] ?? now()->startOfYear()->toDateString(),
            'end_date' => $attributes['end_date'] ?? now()->endOfYear()->toDateString(),
            'goal_setting_start' => $attributes['goal_setting_start'] ?? null,
            'goal_setting_end' => $attributes['goal_setting_end'] ?? null,
            'mid_review_at' => $attributes['mid_review_at'] ?? null,
            'review_start' => $attributes['review_start'] ?? null,
            'review_end' => $attributes['review_end'] ?? null,
            'calibration_at' => $attributes['calibration_at'] ?? null,
            'status' => 'DRAFT',
            'rating_scale_id' => $scale?->getKey(),
            'weight_goals' => (float) ($attributes['weight_goals'] ?? 60),
            'weight_competency' => (float) ($attributes['weight_competency'] ?? 40),
            'created_by' => $actor->getKey(),
        ]);
    }

    /**
     * Membuka siklus: seluruh pegawai aktif pada unit siklus menjadi peserta,
     * dengan penilai dari atasan langsungnya.
     *
     * @return array<string, int>
     */
    public function openCycle(HrPerformanceCycle $cycle, User $actor): array
    {
        $employees = HrEmployee::query()
            ->where('lifecycle_state', 'active')
            ->when($cycle->organization_id !== null, fn ($query) => $query->where('organization_id', $cycle->organization_id))
            ->get();

        $created = 0;

        foreach ($employees as $employee) {
            $participant = HrPerformanceCycleParticipant::query()->firstOrCreate(
                ['cycle_id' => $cycle->getKey(), 'employee_id' => $employee->getKey()],
                [
                    'reviewer_employee_id' => $employee->manager_employee_id ?? null,
                    'organization_id' => $employee->organization_id,
                    'status' => 'INVITED',
                ],
            );

            if ($participant->wasRecentlyCreated) {
                $created++;
            }
        }

        $cycle->forceFill(['status' => 'GOAL_SETTING'])->save();

        $this->events->emit('PERFORMANCE', 'cycle.opened', [
            'actor' => $actor,
            'subject_type' => HrPerformanceCycle::class,
            'subject_id' => $cycle->getKey(),
            'title' => 'Siklus kinerja '.$cycle->code.' dibuka',
            'payload' => ['participants' => $created],
        ]);

        return ['participants_created' => $created, 'participants_total' => $cycle->participants()->count()];
    }

    // ------------------------------------------------------------------ sasaran & OKR

    /**
     * @param array<string, mixed> $attributes
     * @param list<array<string, mixed>> $keyResults
     */
    public function createGoal(HrEmployee $employee, array $attributes, User $actor, array $keyResults = []): HrPerformanceGoal
    {
        $totalWeight = array_sum(array_map(fn (array $kr) => (float) ($kr['weight'] ?? 0), $keyResults));

        return DB::transaction(function () use ($employee, $attributes, $actor, $keyResults, $totalWeight) {
            $goal = HrPerformanceGoal::query()->create([
                'code' => sprintf('GOAL-%s-%05d', now()->format('Y'), HrPerformanceGoal::query()->whereYear('created_at', now()->year)->count() + 1),
                'cycle_id' => $attributes['cycle_id'] ?? null,
                'employee_id' => $employee->getKey(),
                'owner_employee_id' => $attributes['owner_employee_id'] ?? $employee->getKey(),
                'parent_goal_id' => $attributes['parent_goal_id'] ?? null,
                'scope' => strtoupper((string) ($attributes['scope'] ?? 'INDIVIDUAL')),
                'goal_type' => strtoupper((string) ($attributes['goal_type'] ?? 'OBJECTIVE')),
                'title' => $attributes['title'] ?? 'Sasaran',
                'description' => $attributes['description'] ?? null,
                'metric' => $attributes['metric'] ?? null,
                'unit' => $attributes['unit'] ?? null,
                'target_value' => $attributes['target_value'] ?? null,
                'target_direction' => strtoupper((string) ($attributes['target_direction'] ?? 'INCREASE')),
                'weight' => (float) ($attributes['weight'] ?? ($totalWeight > 0 ? $totalWeight : 0)),
                'start_date' => $attributes['start_date'] ?? null,
                'due_date' => $attributes['due_date'] ?? null,
                'progress' => 0,
                'status' => strtoupper((string) ($attributes['status'] ?? 'ACTIVE')),
                'kpi_definition_id' => $attributes['kpi_definition_id'] ?? null,
                'source' => $attributes['source'] ?? 'MANUAL',
            ]);

            foreach ($keyResults as $kr) {
                HrGoalKeyResult::query()->create([
                    'goal_id' => $goal->getKey(),
                    'title' => $kr['title'] ?? 'Key Result',
                    'metric' => $kr['metric'] ?? null,
                    'unit' => $kr['unit'] ?? null,
                    'target_value' => (float) ($kr['target_value'] ?? 0),
                    'current_value' => (float) ($kr['current_value'] ?? 0),
                    'weight' => (float) ($kr['weight'] ?? 1),
                    'progress' => (float) ($kr['progress'] ?? 0),
                    'due_date' => $kr['due_date'] ?? null,
                    'status' => 'ACTIVE',
                    'owner_employee_id' => $employee->getKey(),
                ]);
            }

            $this->events->emit('PERFORMANCE', 'goal.created', [
                'actor' => $actor,
                'employee_id' => $employee->getKey(),
                'subject_type' => HrPerformanceGoal::class,
                'subject_id' => $goal->getKey(),
                'title' => 'Sasaran baru: '.$goal->title,
                'payload' => ['key_results' => count($keyResults), 'cycle_id' => $goal->cycle_id],
            ]);

            return $this->recalculateGoal($goal->refresh(), $actor);
        });
    }

    /**
     * Memperbarui kemajuan; key result yang diisi otomatis menggerakkan sasaran.
     *
     * @param array<string, mixed> $attributes
     */
    public function updateProgress(HrPerformanceGoal $goal, array $attributes, User $actor): HrPerformanceGoal
    {
        return DB::transaction(function () use ($goal, $attributes, $actor) {
            $keyResult = null;

            if (isset($attributes['key_result_id'])) {
                $keyResult = HrGoalKeyResult::query()->find($attributes['key_result_id']);
            }

            $progress = (float) ($attributes['progress'] ?? $keyResult?->progress ?? $goal->progress);

            if ($keyResult !== null) {
                $current = (float) ($attributes['current_value'] ?? $keyResult->current_value);
                $target = (float) $keyResult->target_value;

                if (isset($attributes['current_value']) && $target > 0) {
                    $progress = min(100, round($current / $target * 100, 2));
                }

                $keyResult->forceFill([
                    'current_value' => $current,
                    'progress' => $progress,
                    'status' => $progress >= 100 ? 'COMPLETED' : 'ACTIVE',
                ])->save();
            }

            HrGoalUpdate::query()->create([
                'goal_id' => $goal->getKey(),
                'key_result_id' => $keyResult?->getKey(),
                'employee_id' => $goal->employee_id,
                'update_type' => strtoupper((string) ($attributes['update_type'] ?? 'PROGRESS')),
                'progress' => $progress,
                'previous_progress' => (float) $goal->progress,
                'comment' => $attributes['comment'] ?? null,
                'blockers' => $attributes['blockers'] ?? null,
                'evidence_path' => $attributes['evidence_path'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            return $this->recalculateGoal($goal->refresh(), $actor);
        });
    }

    /** Kemajuan sasaran = rata-rata berbobot key result (OKR berbobot). */
    public function recalculateGoal(HrPerformanceGoal $goal, ?User $actor = null): HrPerformanceGoal
    {
        $keyResults = $goal->keyResults()->get();

        if ($keyResults->isNotEmpty()) {
            $weighted = $keyResults->sum(fn (HrGoalKeyResult $kr) => (float) $kr->progress * (float) $kr->weight);
            $weight = $keyResults->sum(fn (HrGoalKeyResult $kr) => (float) $kr->weight);

            $progress = $weight > 0 ? round($weighted / $weight, 2) : 0.0;
        } else {
            $progress = (float) $goal->progress;
        }

        $overdue = $goal->due_date !== null && $goal->due_date->isPast() && $progress < 100;

        $goal->forceFill([
            'progress' => $progress,
            'status' => $progress >= 100 ? 'COMPLETED' : ($overdue ? 'OVERDUE' : ($goal->status === 'DRAFT' ? 'ACTIVE' : $goal->status)),
        ])->save();

        return $goal->refresh();
    }

    /** @return array<string, mixed> */
    public function goalProgress(int $employeeId, ?string $cycleCode = null): array
    {
        $goals = HrPerformanceGoal::query()
            ->with('keyResults')
            ->where('employee_id', $employeeId)
            ->when($cycleCode !== null, fn ($query) => $query->whereHas('cycle', fn ($c) => $c->where('code', $cycleCode)))
            ->get();

        $weight = $goals->sum(fn (HrPerformanceGoal $goal) => (float) $goal->weight);

        return [
            'goals' => $goals->count(),
            'completed' => $goals->where('status', 'COMPLETED')->count(),
            'overdue' => $goals->where('status', 'OVERDUE')->count(),
            'average_progress' => $goals->isEmpty() ? 0.0 : round($goals->avg('progress'), 2),
            'weighted_progress' => $weight > 0
                ? round($goals->sum(fn (HrPerformanceGoal $goal) => (float) $goal->progress * (float) $goal->weight) / $weight, 2)
                : 0.0,
            'items' => $goals->map(fn (HrPerformanceGoal $goal) => [
                'title' => $goal->title,
                'progress' => (float) $goal->progress,
                'status' => $goal->status,
                'due_date' => $goal->due_date?->toDateString(),
                'key_results' => $goal->keyResults->map->only(['title', 'progress', 'target_value', 'current_value'])->all(),
            ])->all(),
        ];
    }

    // ------------------------------------------------------------------ kompetensi

    /** @param array<string, mixed> $attributes @param list<array<string, mixed>> $levels */
    public function createCompetency(array $attributes, User $actor, array $levels = []): HrCompetencyCatalog
    {
        return DB::transaction(function () use ($attributes, $levels) {
            $competency = HrCompetencyCatalog::query()->updateOrCreate(
                ['code' => strtoupper((string) $attributes['code'])],
                [
                    'name' => $attributes['name'] ?? $attributes['code'],
                    'category' => strtoupper((string) ($attributes['category'] ?? 'CORE')),
                    'description' => $attributes['description'] ?? null,
                    'max_level' => (int) ($attributes['max_level'] ?? 5),
                    'weight' => (float) ($attributes['weight'] ?? 0),
                    'skill_code' => $attributes['skill_code'] ?? null,
                ],
            );

            foreach ($levels as $level) {
                HrCompetencyLevel::query()->updateOrCreate(
                    ['competency_id' => $competency->getKey(), 'level' => (int) $level['level']],
                    [
                        'label' => $level['label'] ?? ('Level '.$level['level']),
                        'behavioral_indicators' => $level['indicators'] ?? null,
                        'description' => $level['description'] ?? null,
                    ],
                );
            }

            return $competency->refresh();
        });
    }

    /**
     * Penilaian kompetensi: tingkat sekarang, target, dan kesenjangannya.
     *
     * @param array<string, mixed> $attributes
     */
    public function assessCompetency(HrEmployee $employee, array $attributes, User $actor): HrPerformanceCompetencyAssessment
    {
        $competency = HrCompetencyCatalog::query()
            ->where('code', strtoupper((string) ($attributes['competency_code'] ?? '')))
            ->orWhere('id', $attributes['competency_id'] ?? 0)
            ->first();

        if ($competency === null) {
            throw HrdValidationException::forField('competency', 'Kompetensi tidak ditemukan.', 'performance.competency_missing');
        }

        $current = (int) ($attributes['current_level'] ?? 0);
        $target = (int) ($attributes['target_level'] ?? $this->targetLevelFor($employee, $competency));

        $assessment = HrPerformanceCompetencyAssessment::query()->updateOrCreate(
            [
                'employee_id' => $employee->getKey(),
                'competency_id' => $competency->getKey(),
                'cycle_id' => $attributes['cycle_id'] ?? null,
            ],
            [
                'current_level' => $current,
                'target_level' => $target,
                'gap' => max(0, $target - $current),
                'evidence' => $attributes['evidence'] ?? null,
                'reviewer_employee_id' => $attributes['reviewer_employee_id'] ?? $employee->manager_employee_id,
                'assessed_at' => now(),
            ],
        );

        $this->events->emit('PERFORMANCE', 'competency.assessed', [
            'actor' => $actor,
            'employee_id' => $employee->getKey(),
            'subject_type' => HrPerformanceCompetencyAssessment::class,
            'subject_id' => $assessment->getKey(),
            'title' => 'Kompetensi '.$competency->name.' dinilai',
            'payload' => ['current' => $current, 'target' => $target, 'gap' => $assessment->gap],
        ]);

        return $assessment->refresh();
    }

    /** @return array<string, mixed> */
    public function competencyGaps(int $employeeId): array
    {
        $assessments = HrPerformanceCompetencyAssessment::query()
            ->with('competency')
            ->where('employee_id', $employeeId)
            ->get();

        $gaps = $assessments->filter(fn (HrPerformanceCompetencyAssessment $assessment) => (int) $assessment->gap > 0);

        return [
            'assessed' => $assessments->count(),
            'with_gap' => $gaps->count(),
            'total_gap' => (int) $assessments->sum('gap'),
            'items' => $gaps->map(fn (HrPerformanceCompetencyAssessment $assessment) => [
                'competency' => $assessment->competency?->name,
                'category' => $assessment->competency?->category,
                'current_level' => (int) $assessment->current_level,
                'target_level' => (int) $assessment->target_level,
                'gap' => (int) $assessment->gap,
            ])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ penilaian

    /**
     * Skor akhir = sasaran (berbobot) + kompetensi (berbobot), lalu dipetakan ke
     * label skala yang dikonfigurasi.
     *
     * @param array<string, mixed> $attributes
     */
    public function submitReview(HrPerformanceReview $review, array $attributes, User $actor): HrPerformanceReview
    {
        return DB::transaction(function () use ($review, $attributes, $actor) {
            $cycle = $review->cycle;

            $goalScore = (float) ($attributes['goal_score'] ?? $this->goalScoreFor((int) $review->employee_id, $cycle?->getKey()));
            $competencyScore = (float) ($attributes['competency_score'] ?? $this->competencyScoreFor((int) $review->employee_id));

            $weightGoals = (float) ($cycle?->weight_goals ?? 60);
            $weightCompetency = (float) ($cycle?->weight_competency ?? 40);
            $weightTotal = max(1, $weightGoals + $weightCompetency);

            // Kemajuan sasaran berskala 0–100, sedangkan kompetensi dan
            // penilaian akhir berskala rating. Tanpa pemetaan ini, satu sasaran
            // yang selesai akan melampaui skala tertinggi.
            $goalScoreOnScale = $this->scaleGoalScore($goalScore, $cycle?->rating_scale_id);

            $overall = round((($goalScoreOnScale * $weightGoals) + ($competencyScore * $weightCompetency)) / $weightTotal, 2);

            $review->forceFill([
                'goal_score' => round($goalScoreOnScale, 2),
                'competency_score' => round($competencyScore, 2),
                'overall_score' => $overall,
                'rating_label' => $this->labelFor($cycle?->rating_scale_id, $overall),
                'strengths' => $attributes['strengths'] ?? $review->strengths,
                'improvements' => $attributes['improvements'] ?? $review->improvements,
                'manager_comments' => $attributes['manager_comments'] ?? $review->manager_comments,
                'employee_comments' => $attributes['employee_comments'] ?? $review->employee_comments,
                'status' => strtoupper((string) ($attributes['status'] ?? 'SUBMITTED')),
                'submitted_at' => now(),
            ])->save();

            $this->events->emit('PERFORMANCE', 'review.submitted', [
                'actor' => $actor,
                'employee_id' => $review->employee_id,
                'subject_type' => HrPerformanceReview::class,
                'subject_id' => $review->getKey(),
                'title' => 'Penilaian kinerja '.$review->review_type.' '.$overall,
                'payload' => ['overall' => $overall, 'label' => $review->rating_label],
            ]);

            return $review->refresh();
        });
    }

    public function startReview(HrPerformanceCycle $cycle, HrEmployee $employee, HrEmployee $reviewer, string $reviewType, User $actor): HrPerformanceReview
    {
        return HrPerformanceReview::query()->firstOrCreate(
            [
                'cycle_id' => $cycle->getKey(),
                'employee_id' => $employee->getKey(),
                'reviewer_employee_id' => $reviewer->getKey(),
                'review_type' => strtoupper($reviewType),
            ],
            [
                'code' => sprintf('REV-%s-%05d', $cycle->code, HrPerformanceReview::query()->where('cycle_id', $cycle->getKey())->count() + 1),
                'status' => 'DRAFT',
            ],
        );
    }

    public function acknowledge(HrPerformanceReview $review, User $actor, ?string $comments = null): HrPerformanceReview
    {
        $review->forceFill([
            'status' => 'ACKNOWLEDGED',
            'acknowledged_at' => now(),
            'employee_comments' => $comments ?? $review->employee_comments,
        ])->save();

        HrPerformanceCycleParticipant::query()
            ->where('cycle_id', $review->cycle_id)
            ->where('employee_id', $review->employee_id)
            ->update(['acknowledged_at' => now(), 'final_rating' => $review->overall_score, 'rating_label' => $review->rating_label]);

        return $review->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function addFeedback(HrEmployee $employee, array $attributes, User $actor): HrPerformanceFeedback
    {
        return HrPerformanceFeedback::query()->create([
            'employee_id' => $employee->getKey(),
            'reviewer_employee_id' => $attributes['reviewer_employee_id'] ?? null,
            'cycle_id' => $attributes['cycle_id'] ?? null,
            'feedback_type' => strtoupper((string) ($attributes['feedback_type'] ?? 'PEER')),
            'visibility' => strtoupper((string) ($attributes['visibility'] ?? 'MANAGER')),
            'message' => $attributes['message'] ?? '',
            'rating' => $attributes['rating'] ?? null,
            'is_anonymous' => (bool) ($attributes['is_anonymous'] ?? false),
            'requested_by' => $actor->getKey(),
            'given_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ kalibrasi & 9-box

    /** @param array<string, mixed> $attributes */
    public function createCalibration(HrPerformanceCycle $cycle, array $attributes, User $actor): HrPerformanceCalibration
    {
        return HrPerformanceCalibration::query()->create([
            'code' => strtoupper((string) ($attributes['code'] ?? 'CAL-'.now()->format('YmdHis'))),
            'cycle_id' => $cycle->getKey(),
            'organization_id' => $attributes['organization_id'] ?? $cycle->organization_id,
            'name' => $attributes['name'] ?? 'Kalibrasi '.$cycle->code,
            'facilitator_employee_id' => $attributes['facilitator_employee_id'] ?? null,
            'scheduled_at' => $attributes['scheduled_at'] ?? now(),
            'status' => 'IN_PROGRESS',
            'distribution' => $this->ratingDistribution($cycle->code),
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function calibrate(HrPerformanceCalibration $calibration, HrEmployee $employee, array $attributes, User $actor): HrCalibrationEntry
    {
        $participant = HrPerformanceCycleParticipant::query()
            ->where('cycle_id', $calibration->cycle_id)
            ->where('employee_id', $employee->getKey())
            ->first();

        $previous = $participant?->final_rating;
        $calibrated = (float) ($attributes['calibrated_rating'] ?? $previous ?? 0);
        $potential = (float) ($attributes['potential_rating'] ?? $participant?->potential_rating ?? 0);

        return DB::transaction(function () use ($calibration, $employee, $attributes, $actor, $participant, $previous, $calibrated, $potential) {
            $entry = HrCalibrationEntry::query()->updateOrCreate(
                ['calibration_id' => $calibration->getKey(), 'employee_id' => $employee->getKey()],
                [
                    'previous_rating' => $previous,
                    'calibrated_rating' => $calibrated,
                    'potential_rating' => $potential,
                    'nine_box_code' => $this->nineBoxCode($calibrated, $potential),
                    'justification' => $attributes['justification'] ?? null,
                    'decided_by' => $actor->getKey(),
                    'decided_at' => now(),
                ],
            );

            // Kalibrasi mengubah angka pada peserta siklus, tetapi alasan dan
            // pelakunya tercatat — tidak ada perubahan senyap (§57).
            $participant?->forceFill([
                'final_rating' => $calibrated,
                'potential_rating' => $potential,
                'nine_box_code' => $entry->nine_box_code,
                'rating_label' => $this->labelFor($calibration->cycle?->rating_scale_id, $calibrated),
            ])->save();

            $this->events->emit('PERFORMANCE', 'calibration.decided', [
                'actor' => $actor,
                'employee_id' => $employee->getKey(),
                'subject_type' => HrCalibrationEntry::class,
                'subject_id' => $entry->getKey(),
                'title' => 'Kalibrasi '.$employee->displayName().': '.$previous.' → '.$calibrated,
                'reason' => $attributes['justification'] ?? null,
                'payload' => ['nine_box' => $entry->nine_box_code],
            ]);

            return $entry->refresh();
        });
    }

    public function completeCalibration(HrPerformanceCalibration $calibration, User $actor): HrPerformanceCalibration
    {
        $calibration->forceFill([
            'status' => 'COMPLETED',
            'distribution' => $this->ratingDistribution($calibration->cycle?->code ?? ''),
        ])->save();

        $calibration->cycle?->forceFill(['status' => 'CALIBRATION'])->save();

        return $calibration->refresh();
    }

    /** @return array<string, mixed> */
    public function ratingDistribution(string $cycleCode, ?int $organizationId = null): array
    {
        $participants = HrPerformanceCycleParticipant::query()
            ->whereHas('cycle', fn ($query) => $query->where('code', $cycleCode))
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->get();

        $rated = $participants->filter(fn (HrPerformanceCycleParticipant $participant) => $participant->final_rating !== null);

        $buckets = ['low' => 0, 'medium' => 0, 'high' => 0];

        foreach ($rated as $participant) {
            $buckets[$this->performanceBand((float) $participant->final_rating)]++;
        }

        return [
            'cycle' => $cycleCode,
            'participants' => $participants->count(),
            'rated' => $rated->count(),
            'average' => $rated->isEmpty() ? null : round($rated->avg('final_rating'), 2),
            'distribution' => $buckets,
            'labels' => $participants->groupBy('rating_label')->map->count()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function latestRating(int $employeeId, ?string $cycleCode = null): ?array
    {
        $participant = HrPerformanceCycleParticipant::query()
            ->with('cycle')
            ->where('employee_id', $employeeId)
            ->when($cycleCode !== null, fn ($query) => $query->whereHas('cycle', fn ($c) => $c->where('code', $cycleCode)))
            ->whereNotNull('final_rating')
            ->orderByDesc('id')
            ->first();

        if ($participant === null) {
            return null;
        }

        return [
            'cycle' => $participant->cycle?->code,
            'rating' => (float) $participant->final_rating,
            'label' => $participant->rating_label,
            'potential' => $participant->potential_rating !== null ? (float) $participant->potential_rating : null,
            'nine_box' => $participant->nine_box_code,
            'status' => $participant->status,
        ];
    }

    /**
     * Matriks kinerja × potensi. Setiap sel membawa sumbernya sehingga hasilnya
     * dapat ditelusuri (§73).
     *
     * @return array<string, mixed>
     */
    public function nineBox(?int $organizationId = null, ?string $cycleCode = null): array
    {
        $participants = HrPerformanceCycleParticipant::query()
            ->with(['employee', 'cycle'])
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($cycleCode !== null, fn ($query) => $query->whereHas('cycle', fn ($c) => $c->where('code', $cycleCode)))
            ->whereNotNull('final_rating')
            ->get();

        $matrix = [];

        foreach ($participants as $participant) {
            $code = $participant->nine_box_code ?? $this->nineBoxCode((float) $participant->final_rating, (float) ($participant->potential_rating ?? 0));

            $matrix[$code][] = [
                'employee_id' => $participant->employee_id,
                'name' => $participant->employee?->displayName(),
                'performance' => (float) $participant->final_rating,
                'potential' => $participant->potential_rating !== null ? (float) $participant->potential_rating : null,
                'cycle' => $participant->cycle?->code,
                'source' => 'performance_cycle_participants',
            ];
        }

        return [
            'cycle' => $cycleCode,
            'cells' => $matrix,
            'totals' => array_map('count', $matrix),
            'cutoffs' => [
                'performance' => [
                    'low' => (float) config('hrd_phase2.performance.nine_box_performance_low', 2.5),
                    'high' => (float) config('hrd_phase2.performance.nine_box_performance_high', 3.8),
                ],
                'potential' => [
                    'low' => (float) config('hrd_phase2.performance.nine_box_potential_low', 2.5),
                    'high' => (float) config('hrd_phase2.performance.nine_box_potential_high', 3.8),
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------ pengembangan & PIP

    /** @param array<string, mixed> $attributes @param list<array<string, mixed>> $actions */
    public function createDevelopmentPlan(HrEmployee $employee, array $attributes, User $actor, array $actions = []): HrDevelopmentPlan
    {
        return DB::transaction(function () use ($employee, $attributes, $actor, $actions) {
            $plan = HrDevelopmentPlan::query()->create([
                'code' => sprintf('DP-%s-%04d', now()->format('Y'), HrDevelopmentPlan::query()->whereYear('created_at', now()->year)->count() + 1),
                'employee_id' => $employee->getKey(),
                'cycle_id' => $attributes['cycle_id'] ?? null,
                'title' => $attributes['title'] ?? 'Rencana Pengembangan',
                'source' => strtoupper((string) ($attributes['source'] ?? 'PERFORMANCE')),
                'objective' => $attributes['objective'] ?? null,
                'status' => 'ACTIVE',
                'start_date' => $attributes['start_date'] ?? now()->toDateString(),
                'target_date' => $attributes['target_date'] ?? now()->addMonths(6)->toDateString(),
                'owner_employee_id' => $attributes['owner_employee_id'] ?? $employee->manager_employee_id,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ]);

            foreach ($actions as $action) {
                $this->addDevelopmentAction($plan, $action, $actor);
            }

            $this->events->emit('PERFORMANCE', 'development_plan.created', [
                'actor' => $actor,
                'employee_id' => $employee->getKey(),
                'subject_type' => HrDevelopmentPlan::class,
                'subject_id' => $plan->getKey(),
                'title' => 'Rencana pengembangan dibuat',
                'payload' => ['actions' => count($actions)],
            ]);

            return $plan->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function addDevelopmentAction(HrDevelopmentPlan $plan, array $attributes, User $actor): HrDevelopmentPlanAction
    {
        return HrDevelopmentPlanAction::query()->create([
            'plan_id' => $plan->getKey(),
            'action_type' => strtoupper((string) ($attributes['action_type'] ?? 'TRAINING')),
            'title' => $attributes['title'] ?? 'Tindakan pengembangan',
            'description' => $attributes['description'] ?? null,
            'skill_code' => $attributes['skill_code'] ?? null,
            'competency_id' => $attributes['competency_id'] ?? null,
            'training_id' => $attributes['training_id'] ?? null,
            'due_date' => $attributes['due_date'] ?? null,
            'status' => 'PENDING',
            'progress' => 0,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function createPip(HrEmployee $employee, HrEmployee $manager, array $attributes, User $actor): HrPerformanceImprovementPlan
    {
        $pip = HrPerformanceImprovementPlan::query()->create([
            'code' => sprintf('PIP-%s-%04d', now()->format('Y'), HrPerformanceImprovementPlan::query()->whereYear('created_at', now()->year)->count() + 1),
            'employee_id' => $employee->getKey(),
            'manager_employee_id' => $manager->getKey(),
            'cycle_id' => $attributes['cycle_id'] ?? null,
            'issue' => $attributes['issue'] ?? 'Performa belum memenuhi harapan',
            'expectations' => $attributes['expectations'] ?? null,
            'support' => $attributes['support'] ?? null,
            'status' => 'ACTIVE',
            'start_date' => $attributes['start_date'] ?? now()->toDateString(),
            'end_date' => $attributes['end_date'] ?? now()->addDays((int) config('hrd_phase2.performance.pip_default_days', 90))->toDateString(),
            'created_by' => $actor->getKey(),
        ]);

        $this->events->emit('PERFORMANCE', 'pip.created', [
            'actor' => $actor,
            'employee_id' => $employee->getKey(),
            'subject_type' => HrPerformanceImprovementPlan::class,
            'subject_id' => $pip->getKey(),
            'title' => 'PIP dimulai untuk '.$employee->displayName(),
            'payload' => ['end_date' => $pip->end_date?->toDateString()],
        ]);

        return $pip;
    }

    /** @param array<string, mixed> $attributes */
    public function reviewPip(HrPerformanceImprovementPlan $pip, array $attributes, User $actor): HrPipReview
    {
        $review = HrPipReview::query()->create([
            'pip_id' => $pip->getKey(),
            'review_date' => $attributes['review_date'] ?? now()->toDateString(),
            'reviewer_employee_id' => $attributes['reviewer_employee_id'] ?? $pip->manager_employee_id,
            'progress' => (float) ($attributes['progress'] ?? 0),
            'status' => strtoupper((string) ($attributes['status'] ?? 'ON_TRACK')),
            'notes' => $attributes['notes'] ?? null,
            'next_steps' => $attributes['next_steps'] ?? null,
        ]);

        if (in_array($review->status, ['FAILED', 'IMPROVED'], true)) {
            $pip->forceFill([
                'status' => $review->status === 'FAILED' ? 'FAILED' : 'IMPROVED',
                'outcome' => $attributes['notes'] ?? null,
            ])->save();
        }

        $this->events->emit('PERFORMANCE', 'pip.reviewed', [
            'actor' => $actor,
            'employee_id' => $pip->employee_id,
            'subject_type' => HrPipReview::class,
            'subject_id' => $review->getKey(),
            'title' => 'Tinjauan PIP: '.$review->status,
        ]);

        return $review;
    }

    public function pipActive(int $employeeId): bool
    {
        return HrPerformanceImprovementPlan::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->exists();
    }

    /** @return array<string, mixed> */
    public function summary(?string $cycleCode = null, ?int $organizationId = null): array
    {
        $cycle = $cycleCode !== null
            ? HrPerformanceCycle::query()->where('code', $cycleCode)->first()
            : HrPerformanceCycle::query()->orderByDesc('id')->first();

        if ($cycle === null) {
            return ['cycle' => null];
        }

        $goals = HrPerformanceGoal::query()->where('cycle_id', $cycle->getKey())->get();
        $reviews = HrPerformanceReview::query()->where('cycle_id', $cycle->getKey())->get();

        return [
            'cycle' => $cycle->code,
            'status' => $cycle->status,
            'participants' => $cycle->participants()->count(),
            'goals' => $goals->count(),
            'goal_completion' => $goals->isEmpty() ? 0.0 : round($goals->where('status', 'COMPLETED')->count() / $goals->count() * 100, 2),
            'average_goal_progress' => $goals->isEmpty() ? 0.0 : round($goals->avg('progress'), 2),
            'reviews' => $reviews->count(),
            'reviews_submitted' => $reviews->whereIn('status', ['SUBMITTED', 'ACKNOWLEDGED'])->count(),
            'average_rating' => $this->ratingDistribution($cycle->code, $organizationId)['average'],
            'rating_distribution' => $this->ratingDistribution($cycle->code, $organizationId)['distribution'],
            'pip_active' => HrPerformanceImprovementPlan::query()->where('status', 'ACTIVE')->count(),
            'development_plans' => HrDevelopmentPlan::query()->where('status', 'ACTIVE')->count(),
            'competency_gap_total' => (int) HrPerformanceCompetencyAssessment::query()->sum('gap'),
        ];
    }

    // ------------------------------------------------------------------ bantuan

    private function goalScoreFor(int $employeeId, ?int $cycleId): float
    {
        $goals = HrPerformanceGoal::query()
            ->where('employee_id', $employeeId)
            ->when($cycleId !== null, fn ($query) => $query->where('cycle_id', $cycleId))
            ->get();

        if ($goals->isEmpty()) {
            return 0.0;
        }

        $weight = $goals->sum(fn (HrPerformanceGoal $goal) => (float) $goal->weight);

        if ($weight > 0) {
            return round($goals->sum(fn (HrPerformanceGoal $goal) => (float) $goal->progress * (float) $goal->weight) / $weight, 2);
        }

        return round($goals->avg('progress'), 2);
    }

    private function competencyScoreFor(int $employeeId): float
    {
        $assessments = HrPerformanceCompetencyAssessment::query()
            ->with('competency')
            ->where('employee_id', $employeeId)
            ->get();

        if ($assessments->isEmpty()) {
            return 0.0;
        }

        // Skor 1–5: tingkat tercapai dibandingkan target.
        $scores = $assessments->map(function (HrPerformanceCompetencyAssessment $assessment) {
            $target = max(1, (int) $assessment->target_level);
            $current = (int) $assessment->current_level;

            return min(5, round($current / $target * 5, 2));
        });

        return round($scores->avg(), 2);
    }

    /** Memetakan kemajuan 0–100 ke rentang skala rating yang berlaku. */
    private function scaleGoalScore(float $progressPercent, ?int $scaleId): float
    {
        $scale = $scaleId !== null ? HrRatingScale::query()->find($scaleId) : null;

        $min = (float) ($scale->min_value ?? 1);
        $max = (float) ($scale->max_value ?? 5);

        return round($min + (min(100, max(0, $progressPercent)) / 100) * ($max - $min), 2);
    }

    private function labelFor(?int $scaleId, float $score): ?string
    {
        if ($scaleId === null) {
            return null;
        }

        $level = HrRatingScaleLevel::query()
            ->where('scale_id', $scaleId)
            ->where('min_value', '<=', $score)
            ->where('max_value', '>=', $score)
            ->orderBy('sequence')
            ->first();

        return $level?->label;
    }

    private function targetLevelFor(HrEmployee $employee, HrCompetencyCatalog $competency): int
    {
        $requirement = \App\Modules\Hrd\Performance\Models\HrPositionCompetencyRequirement::query()
            ->where('competency_id', $competency->getKey())
            ->where(fn ($query) => $query->where('position_id', $employee->position_id)->orWhereNull('position_id'))
            ->orderByDesc('required_level')
            ->first();

        return (int) ($requirement?->required_level ?? $competency->max_level ?? 5);
    }

    private function performanceBand(float $score): string
    {
        $low = (float) config('hrd_phase2.performance.nine_box_performance_low', 2.5);
        $high = (float) config('hrd_phase2.performance.nine_box_performance_high', 3.8);

        return $score >= $high ? 'high' : ($score >= $low ? 'medium' : 'low');
    }

    private function potentialBand(float $score): string
    {
        $low = (float) config('hrd_phase2.performance.nine_box_potential_low', 2.5);
        $high = (float) config('hrd_phase2.performance.nine_box_potential_high', 3.8);

        return $score >= $high ? 'high' : ($score >= $low ? 'medium' : 'low');
    }

    private function nineBoxCode(float $performance, float $potential): string
    {
        $map = [
            'low' => ['low' => 'LOW_LOW', 'medium' => 'LOW_MEDIUM', 'high' => 'LOW_HIGH'],
            'medium' => ['low' => 'MEDIUM_LOW', 'medium' => 'MEDIUM_MEDIUM', 'high' => 'MEDIUM_HIGH'],
            'high' => ['low' => 'HIGH_LOW', 'medium' => 'HIGH_MEDIUM', 'high' => 'HIGH_HIGH'],
        ];

        return $map[$this->performanceBand($performance)][$this->potentialBand($potential)];
    }
}
