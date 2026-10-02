<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Talent\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeSkill;
use App\Modules\Hrd\Strategic\Support\PeopleFacts;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use App\Modules\Hrd\Strategic\Talent\Models\HrEmployeeAvailability;
use App\Modules\Hrd\Strategic\Talent\Models\HrEmployeeInterest;
use App\Modules\Hrd\Strategic\Talent\Models\HrEmployeeOpportunityMatch;
use App\Modules\Hrd\Strategic\Talent\Models\HrOpportunityRequirement;
use App\Modules\Hrd\Strategic\Talent\Models\HrOpportunityType;
use App\Modules\Hrd\Strategic\Talent\Models\HrProjectAssignment;
use App\Modules\Hrd\Strategic\Talent\Models\HrTalentOpportunity;
use App\Modules\Hrd\Strategic\Talent\Models\HrTemporaryAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Internal Talent Marketplace (§28, §29, §92, §95–§97).
 * Skor kecocokan adalah SINYAL; semua hasil menunggu tinjauan manusia.
 */
class TalentMarketplaceService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly PeopleFacts $facts,
        private readonly TalentIntelligenceService $talent,
    ) {}

    public function defineOpportunityType(array $data): HrOpportunityType
    {
        return HrOpportunityType::query()->updateOrCreate(
            ['code' => strtoupper((string) $data['code'])],
            [
                'name' => $data['name'], 'category' => strtoupper((string) ($data['category'] ?? 'PROJECT')),
                'duration_days' => (int) ($data['duration_days'] ?? 90),
                'allocation_default' => (float) ($data['allocation_default'] ?? 20),
                'is_paid' => (bool) ($data['is_paid'] ?? false), 'is_active' => true,
            ]
        );
    }

    public function publish(array $data, mixed $actor = null): HrTalentOpportunity
    {
        $opportunity = HrTalentOpportunity::query()->create([
            'code' => $data['code'] ?? $this->ctx->nextCode('hr_talent_opportunities', 'OPP'),
            'opportunity_type_code' => strtoupper((string) ($data['opportunity_type'] ?? 'PROJECT')),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'organization_id' => $data['organization_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'position_id' => $data['position_id'] ?? null,
            'job_id' => $data['job_id'] ?? null,
            'project_reference' => $data['project_reference'] ?? null,
            'duration_days' => (int) ($data['duration_days'] ?? 90),
            'allocation_percent' => (float) ($data['allocation_percent'] ?? 20),
            'start_date' => $data['start_date'] ?? now()->toDateString(),
            'end_date' => $data['end_date'] ?? now()->addDays((int) ($data['duration_days'] ?? 90))->toDateString(),
            'required_skills' => $data['required_skills'] ?? [],
            'preferred_skills' => $data['preferred_skills'] ?? [],
            'min_experience_years' => (float) ($data['min_experience_years'] ?? 0),
            'required_certification' => $data['required_certification'] ?? null,
            'visibility' => strtoupper((string) ($data['visibility'] ?? 'PUBLIC')),
            'status' => 'OPEN',
            'spots' => (int) ($data['spots'] ?? 1),
            'created_by' => $this->ctx->employeeFor($actor)?->getKey(),
            'published_at' => now(),
            'closes_at' => $data['closes_at'] ?? now()->addDays(30),
        ]);

        foreach ((array) ($data['requirements'] ?? []) as $requirement) {
            HrOpportunityRequirement::query()->create([
                'opportunity_id' => $opportunity->getKey(),
                'requirement_type' => strtoupper((string) ($requirement['type'] ?? 'SKILL')),
                'key' => (string) ($requirement['key'] ?? ''),
                'required_level' => $requirement['level'] ?? null,
                'required_value' => $requirement['value'] ?? null,
                'weight' => (float) ($requirement['weight'] ?? 1),
                'is_mandatory' => (bool) ($requirement['mandatory'] ?? false),
            ]);
        }

        $this->ctx->audit('strategic.opportunity.published', HrTalentOpportunity::class, $opportunity->getKey(), [
            'title' => $opportunity->title, 'type' => $opportunity->opportunity_type_code,
        ], null, $actor);
        $this->ctx->event('TALENT_MARKETPLACE', 'OpportunityPublished', [
            'title' => 'Peluang internal dipublikasikan', 'description' => $opportunity->title,
        ], null, HrTalentOpportunity::class, $opportunity->getKey(), $actor);

        return $opportunity->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function match(HrTalentOpportunity $opportunity, array $options = []): array
    {
        $weights = (array) $this->ctx->config('marketplace.weights', []);
        $bands = (array) $this->ctx->config('marketplace.bands', []);
        $minScore = (float) ($options['min_score'] ?? $this->ctx->config('marketplace.min_match_score', 40));
        $candidates = HrEmployee::query()->where('is_active', true)
            ->when($options['organization_id'] ?? $opportunity->organization_id, fn ($q, $org) => $q->where('organization_id', $org))
            ->limit((int) ($options['limit'] ?? 150))->get(['id', 'employee_number', 'position_id', 'department_id', 'location_id', 'organization_id']);

        $required = $this->normaliseSkills((array) $opportunity->required_skills);
        $preferred = $this->normaliseSkills((array) $opportunity->preferred_skills);
        $matches = [];

        foreach ($candidates as $candidate) {
            $employeeId = (int) $candidate->getKey();
            $employeeSkills = HrEmployeeSkill::query()->where('employee_id', $employeeId)->with('skill')->get()
                ->mapWithKeys(fn (HrEmployeeSkill $row): array => [strtoupper((string) $row->skill?->code) => (string) $row->proficiency_level_code])->all();

            $factors = [
                'skill' => $this->skillScore($required, $preferred, $employeeSkills),
                'experience' => min(100.0, ($this->facts->tenureMonths($employeeId) / max((float) ($opportunity->min_experience_years * 12), 12)) * 100),
                'interest' => $this->interestScore($employeeId, $required),
                'career_goal' => $this->careerGoalScore($employeeId, $opportunity),
                'location' => ($opportunity->location_id && $candidate->location_id === $opportunity->location_id) ? 100.0 : ($opportunity->location_id ? 60.0 : 80.0),
                'availability' => $this->availabilityScore($employeeId),
                'certification' => $this->certificationScore($employeeId, $opportunity),
                'performance' => min(100.0, (($this->facts->performanceScore($employeeId) ?? 3.0) / 5) * 100),
            ];

            $matchScore = $this->ctx->score(
                $factors['skill'] * ($weights['skill'] ?? 0.35) + $factors['experience'] * ($weights['experience'] ?? 0.15)
                + $factors['interest'] * ($weights['interest'] ?? 0.10) + $factors['career_goal'] * ($weights['career_goal'] ?? 0.10)
                + $factors['location'] * ($weights['location'] ?? 0.05) + $factors['availability'] * ($weights['availability'] ?? 0.10)
                + $factors['certification'] * ($weights['certification'] ?? 0.05) + $factors['performance'] * ($weights['performance'] ?? 0.10)
            );

            if ($matchScore < $minScore) {
                continue;
            }

            $scores = array_map(fn (float $value): float => $this->ctx->score($value), $factors);
            $row = HrEmployeeOpportunityMatch::query()->updateOrCreate(
                ['opportunity_id' => $opportunity->getKey(), 'employee_id' => $employeeId],
                [
                    'match_score' => $matchScore, 'skill_score' => $scores['skill'], 'experience_score' => $scores['experience'],
                    'interest_score' => $scores['interest'], 'career_goal_score' => $scores['career_goal'],
                    'location_score' => $scores['location'], 'availability_score' => $scores['availability'],
                    'certification_score' => $scores['certification'], 'performance_score' => $scores['performance'],
                    'factors' => $scores, 'recommendation' => $this->band($matchScore, $bands),
                    'human_review_status' => 'PENDING', 'generated_at' => now(),
                ]
            );

            $matches[] = [
                'match_id' => $row->getKey(), 'employee_id' => $employeeId, 'employee_number' => $candidate->employee_number,
                'match_score' => $matchScore, 'recommendation' => $row->recommendation, 'factors' => $scores,
                'evidence' => [
                    'matched_skills' => array_values(array_intersect(array_keys($employeeSkills), array_keys($required))),
                    'missing_skills' => array_values(array_diff(array_keys($required), array_keys($employeeSkills))),
                    'availability_percent' => $scores['availability'],
                ],
                'human_review_status' => 'PENDING',
            ];
        }

        usort($matches, fn (array $a, array $b): int => $b['match_score'] <=> $a['match_score']);
        $this->ctx->audit('strategic.opportunity.matched', HrTalentOpportunity::class, $opportunity->getKey(), [
            'candidates' => count($matches), 'min_score' => $minScore,
        ]);

        return [
            'opportunity' => ['code' => $opportunity->code, 'title' => $opportunity->title],
            'candidates' => $matches, 'count' => count($matches),
            'note' => 'Skor kecocokan adalah rekomendasi untuk ditinjau; keputusan tetap di manusia.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function matchForEmployee(int $employeeId, array $options = []): array
    {
        $rows = [];
        foreach (HrTalentOpportunity::query()->whereIn('status', ['OPEN', 'MATCHING'])
            ->when($options['organization_id'] ?? null, fn ($q, $org) => $q->where('organization_id', $org))
            ->limit((int) ($options['limit'] ?? 20))->get() as $opportunity) {
            foreach ($this->match($opportunity, ['limit' => 400, 'min_score' => 0])['candidates'] as $candidate) {
                if ($candidate['employee_id'] === $employeeId) {
                    $rows[] = ['opportunity_code' => $opportunity->code, 'title' => $opportunity->title] + $candidate;
                }
            }
        }
        usort($rows, fn (array $a, array $b): int => $b['match_score'] <=> $a['match_score']);

        return ['employee_id' => $employeeId, 'matches' => $rows, 'count' => count($rows)];
    }

    public function review(int $matchId, string $status, mixed $actor = null, ?string $notes = null): HrEmployeeOpportunityMatch
    {
        $match = HrEmployeeOpportunityMatch::query()->findOrFail($matchId);
        $match->forceFill([
            'human_review_status' => strtoupper($status),
            'reviewed_by' => $this->ctx->employeeFor($actor)?->getKey(),
            'reviewed_at' => now(), 'notes' => $notes,
        ])->save();

        $this->ctx->audit('strategic.opportunity.match_reviewed', HrEmployeeOpportunityMatch::class, $match->getKey(), [
            'status' => $match->human_review_status, 'employee_id' => $match->employee_id,
        ], $notes, $actor, (int) $match->employee_id);

        return $match->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function mobilityCandidates(string $skillCode, ?int $organizationId = null, int $limit = 20): array
    {
        $rows = $this->ctx->safe(fn (): array => DB::table('hr_employee_skills as es')
            ->join('hr_skills as s', 's.id', '=', 'es.skill_id')
            ->join('hr_employees as e', 'e.id', '=', 'es.employee_id')
            ->where('e.is_active', true)->where('s.code', $skillCode)
            ->when($organizationId, fn ($q) => $q->where('e.organization_id', $organizationId))
            ->selectRaw('e.id as employee_id, e.employee_number as employee_number, es.proficiency_level_code as level, e.position_id as position_id')
            ->limit($limit)->get()->map(fn ($row): array => [
                'employee_id' => (int) $row->employee_id, 'employee_number' => $row->employee_number,
                'level' => $row->level, 'position_id' => $row->position_id !== null ? (int) $row->position_id : null,
                'availability_percent' => $this->availabilityScore((int) $row->employee_id),
                'readiness' => $this->talent->readiness((int) $row->employee_id)['level'],
            ])->all(), []);

        return ['skill_code' => $skillCode, 'candidates' => $rows, 'count' => count($rows), 'note' => 'Pool kandidat untuk ditinjau HR/atasan.'];
    }

    public function assignProject(array $data, mixed $actor = null): HrProjectAssignment
    {
        $allocation = (float) ($data['allocation_percent'] ?? 20);
        $capacity = (float) $this->ctx->config('marketplace.max_allocation_percent', 100);
        $current = $this->currentAllocation((int) $data['employee_id']);

        $assignment = HrProjectAssignment::query()->create([
            'employee_id' => (int) $data['employee_id'],
            'opportunity_id' => $data['opportunity_id'] ?? null,
            'project_code' => $data['project_code'] ?? null,
            'project_name' => $data['project_name'] ?? 'Proyek internal',
            'role_title' => $data['role_title'] ?? null,
            'allocation_percent' => $allocation,
            'start_date' => $data['start_date'] ?? now()->toDateString(),
            'end_date' => $data['end_date'] ?? now()->addDays((int) ($data['duration_days'] ?? 90))->toDateString(),
            'primary_job_overlap' => (bool) ($data['primary_job_overlap'] ?? (($current + $allocation) > $capacity)),
            'status' => 'PLANNED',
            'manager_employee_id' => $data['manager_employee_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->ctx->audit('strategic.project_assignment.created', HrProjectAssignment::class, $assignment->getKey(), [
            'employee_id' => $assignment->employee_id, 'allocation' => $allocation,
            'total_allocation' => $current + $allocation, 'over_capacity' => ($current + $allocation) > $capacity,
            'override_reason' => $data['override_reason'] ?? null,
        ], $data['override_reason'] ?? null, $actor, (int) $assignment->employee_id);

        return $assignment->refresh();
    }

    public function assignTemporary(array $data, mixed $actor = null): HrTemporaryAssignment
    {
        $assignment = HrTemporaryAssignment::query()->create([
            'employee_id' => (int) $data['employee_id'],
            'assignment_type' => strtoupper((string) ($data['assignment_type'] ?? 'TEMPORARY')),
            'from_position_id' => $data['from_position_id'] ?? HrEmployee::query()->whereKey($data['employee_id'])->value('position_id'),
            'to_position_id' => $data['to_position_id'] ?? null,
            'to_organization_id' => $data['to_organization_id'] ?? null,
            'start_date' => $data['start_date'] ?? now()->toDateString(),
            'end_date' => $data['end_date'] ?? now()->addMonths(3)->toDateString(),
            'workload_percent' => (float) ($data['workload_percent'] ?? 50),
            'status' => 'PLANNED', 'reason' => $data['reason'] ?? null,
        ]);

        $this->ctx->audit('strategic.temporary_assignment.created', HrTemporaryAssignment::class, $assignment->getKey(), [
            'employee_id' => $assignment->employee_id, 'type' => $assignment->assignment_type,
        ], $data['reason'] ?? null, $actor, (int) $assignment->employee_id);

        return $assignment;
    }

    /**
     * @return array<string, mixed>
     */
    public function allocationAnalysis(?int $organizationId = null, int $limit = 200): array
    {
        $capacity = (float) $this->ctx->config('marketplace.max_allocation_percent', 100);
        $rows = [];
        foreach (HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->limit($limit)->pluck('id') as $employeeId) {
            $allocated = $this->currentAllocation((int) $employeeId);
            $rows[] = [
                'employee_id' => (int) $employeeId, 'allocated_percent' => $allocated, 'capacity_percent' => $capacity,
                'available_percent' => max($capacity - $allocated, 0), 'over_allocated' => $allocated > $capacity,
            ];
        }

        return [
            'rows' => $rows,
            'over_allocated' => count(array_filter($rows, fn (array $row): bool => $row['over_allocated'])),
            'average_allocation' => $rows === [] ? 0.0 : $this->ctx->score(array_sum(array_column($rows, 'allocated_percent')) / count($rows)),
            'note' => 'Alokasi melebihi kapasitas hanya boleh dengan alasan yang tercatat.',
        ];
    }

    public function recordInterest(int $employeeId, string $type, string $key, float $weight = 1.0, string $source = 'SELF', mixed $actor = null): HrEmployeeInterest
    {
        $interest = HrEmployeeInterest::query()->updateOrCreate(
            ['employee_id' => $employeeId, 'interest_type' => strtoupper($type), 'key' => $key],
            ['weight' => $weight, 'source' => strtoupper($source)]
        );

        $this->ctx->audit('strategic.employee_interest.recorded', HrEmployeeInterest::class, $interest->getKey(), [
            'employee_id' => $employeeId, 'interest' => $type.':'.$key,
        ], null, $actor, $employeeId);

        return $interest;
    }

    public function recordAvailability(int $employeeId, array $data, mixed $actor = null): HrEmployeeAvailability
    {
        $allocated = $this->currentAllocation($employeeId);
        $capacity = (float) ($data['capacity_percent'] ?? $this->ctx->config('marketplace.max_allocation_percent', 100));

        return HrEmployeeAvailability::query()->updateOrCreate(
            ['employee_id' => $employeeId, 'as_of' => $data['as_of'] ?? now()->toDateString()],
            [
                'capacity_percent' => $capacity, 'allocated_percent' => $allocated,
                'available_percent' => max($capacity - $allocated, 0),
                'available_from' => $data['available_from'] ?? null, 'notes' => $data['notes'] ?? null,
            ]
        );
    }

    /**
     * @param  array<int, mixed>  $skills
     * @return array<string, float>
     */
    private function normaliseSkills(array $skills): array
    {
        $levels = (array) $this->ctx->config('skills.levels', []);
        $normalised = [];
        foreach ($skills as $skill) {
            if (is_string($skill)) {
                $normalised[strtoupper($skill)] = 3.0;
            } elseif (is_array($skill)) {
                $code = strtoupper((string) ($skill['code'] ?? $skill['skill_code'] ?? ''));
                if ($code !== '') {
                    $normalised[$code] = (float) ($skill['ordinal'] ?? $levels[strtoupper((string) ($skill['level'] ?? 'ADVANCED'))] ?? 3);
                }
            }
        }

        return $normalised;
    }

    /**
     * @param  array<string, float>  $required
     * @param  array<string, float>  $preferred
     * @param  array<string, string>  $employeeSkills
     */
    private function skillScore(array $required, array $preferred, array $employeeSkills): float
    {
        $levels = (array) $this->ctx->config('skills.levels', []);
        $total = $earned = 0.0;
        foreach ($required as $code => $requiredOrdinal) {
            $total += $requiredOrdinal * 2;
            $earned += min((float) ($levels[strtoupper((string) ($employeeSkills[$code] ?? 'NONE'))] ?? 0), $requiredOrdinal) * 2;
        }
        foreach ($preferred as $code => $requiredOrdinal) {
            $total += $requiredOrdinal;
            $earned += min((float) ($levels[strtoupper((string) ($employeeSkills[$code] ?? 'NONE'))] ?? 0), $requiredOrdinal);
        }

        return $total > 0 ? $this->ctx->percent($earned, $total) : 60.0;
    }

    /**
     * @param  array<string, float>  $required
     */
    private function interestScore(int $employeeId, array $required): float
    {
        $interests = HrEmployeeInterest::query()->where('employee_id', $employeeId)->get();
        if ($interests->isEmpty()) {
            return 50.0;
        }
        $keys = array_keys($required);
        $hit = $interests->filter(fn (HrEmployeeInterest $row): bool => in_array(strtoupper((string) $row->key), $keys, true)
            || strtoupper((string) $row->interest_type) === 'PROJECT')->count();

        return $hit > 0 ? min(100.0, 60 + ($hit * 20)) : 40.0;
    }

    private function careerGoalScore(int $employeeId, HrTalentOpportunity $opportunity): float
    {
        return $this->ctx->safe(function () use ($employeeId, $opportunity): float {
            $target = DB::table('hr_employee_career_plans')->where('employee_id', $employeeId)->orderByDesc('id')->value('target_position_id');

            return $target ? ((int) $target === (int) $opportunity->position_id ? 100.0 : 55.0) : 50.0;
        }, 50.0);
    }

    private function availabilityScore(int $employeeId): float
    {
        $availability = $this->ctx->safe(fn () => HrEmployeeAvailability::query()->where('employee_id', $employeeId)->orderByDesc('as_of')->first(), null);
        if ($availability) {
            return (float) $availability->available_percent;
        }

        return max((float) $this->ctx->config('marketplace.max_allocation_percent', 100) - $this->currentAllocation($employeeId), 0);
    }

    private function certificationScore(int $employeeId, HrTalentOpportunity $opportunity): float
    {
        if (! $opportunity->required_certification) {
            return 70.0;
        }

        return count(array_filter($this->facts->certifications($employeeId), fn (array $row): bool => str_contains(
            mb_strtolower((string) $row['name']), mb_strtolower((string) $opportunity->required_certification)
        ))) > 0 ? 100.0 : 30.0;
    }

    private function currentAllocation(int $employeeId): float
    {
        return (float) $this->ctx->safe(fn (): float => (float) HrProjectAssignment::query()
            ->where('employee_id', $employeeId)->whereIn('status', ['PLANNED', 'ACTIVE'])->sum('allocation_percent'), 0.0);
    }

    /**
     * @param  array<string, float>  $bands
     */
    private function band(float $score, array $bands): string
    {
        foreach ($bands as $band => $threshold) {
            if ($score >= (float) $threshold) {
                return (string) $band;
            }
        }

        return 'PARTIAL';
    }
}
