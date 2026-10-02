<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Talent\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeSkill;
use App\Modules\Hrd\Strategic\Skills\Services\SkillsIntelligenceService;
use App\Modules\Hrd\Strategic\Support\PeopleFacts;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use App\Modules\Hrd\Talent\Models\HrTalentPoolMember;
use App\Modules\Hrd\Talent\Models\HrTalentReviewResult;
use Illuminate\Support\Facades\DB;

/**
 * Talent Intelligence Hub: menyatukan employee, keterampilan, kompetensi,
 * kinerja, tujuan, pembelajaran, karier, potensi, pengalaman, prestasi,
 * sertifikasi, dan mobilitas menjadi satu portofolio pertumbuhan (§25–§30).
 */
class TalentIntelligenceService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly PeopleFacts $facts,
        private readonly SkillsIntelligenceService $skills,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function growthPortfolio(int $employeeId): array
    {
        $employee = HrEmployee::query()->with(['position', 'department', 'organization'])->find($employeeId);
        if (! $employee) {
            return ['employee_id' => $employeeId, 'found' => false];
        }

        $skills = HrEmployeeSkill::query()->where('employee_id', $employeeId)->with('skill')->get()
            ->map(fn (HrEmployeeSkill $row): array => [
                'code' => $row->skill?->code, 'name' => $row->skill?->name,
                'level' => $row->proficiency_level_code, 'verified' => (bool) $row->verified,
                'confidence' => $this->skills->confidenceFor($employeeId, (string) $row->skill?->code)['confidence'],
                'last_assessed_at' => $row->last_assessed_at?->toDateString(),
            ])->all();

        $interests = $this->ctx->safe(fn (): array => DB::table('hr_employee_interests')->where('employee_id', $employeeId)->get()
            ->map(fn ($row): array => ['type' => $row->interest_type, 'key' => $row->key, 'weight' => (float) $row->weight])->all(), []);

        $aspirations = $this->ctx->safe(fn (): array => DB::table('hr_employee_career_plans')->where('employee_id', $employeeId)
            ->orderByDesc('id')->limit(3)->get()->map(fn ($row): array => [
                'target_position_id' => $row->target_position_id, 'readiness_level' => $row->readiness_level, 'status' => $row->status,
            ])->all(), []);

        $talentReview = $this->facts->talentReview($employeeId);

        return [
            'found' => true,
            'employee' => [
                'id' => $employee->getKey(), 'number' => $employee->employee_number,
                'position' => $employee->position?->title, 'department' => $employee->department?->name,
                'organization_id' => $employee->organization_id, 'hire_date' => $employee->hire_date?->toDateString(),
                'worker_type' => $employee->worker_type,
            ],
            'skills' => $skills,
            'skill_lifecycle' => $this->skills->lifecycle($employeeId)['by_state'],
            'competencies' => $this->facts->competencyScores($employeeId),
            'performance' => ['score' => $this->facts->performanceScore($employeeId), 'goals' => $this->facts->goals($employeeId)],
            'potential' => [
                'score' => $this->facts->potentialScore($employeeId),
                'nine_box' => $talentReview['nine_box_code'] ?? null, 'readiness' => $talentReview['readiness'] ?? null,
            ],
            'interests' => $interests,
            'career_aspirations' => $aspirations,
            'experience' => [
                'tenure_months' => $this->facts->tenureMonths($employeeId),
                'projects' => $this->facts->projectAssignments($employeeId),
                'mentoring' => $this->facts->mentoring($employeeId),
                'months_since_promotion' => $this->facts->monthsSincePromotion($employeeId),
            ],
            'certifications' => $this->facts->certifications($employeeId),
            'learning' => [
                'completions' => $this->facts->trainingCompletions($employeeId),
                'participation_percent' => $this->facts->learningParticipation($employeeId),
            ],
            'achievements' => $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_recognitions')
                ? DB::table('hr_recognitions')->where('employee_id', $employeeId)->get()->map(fn ($row): array => [
                    'type' => $row->recognition_type ?? null, 'title' => $row->title ?? null, 'given_at' => $row->recognized_at ?? null,
                ])->all() : [], []),
            'retention' => $this->ctx->safe(fn (): ?array => ($row = DB::table('hr_retention_signals')->where('employee_id', $employeeId)->orderByDesc('id')->first())
                ? ['signal_level' => $row->signal_level, 'retention_score' => (float) $row->retention_score, 'status' => $row->status] : null, null),
            'data_sources' => ['phase1' => 'hr_employee_skills, hr_certifications', 'phase2' => 'performance, learning, career, talent'],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Enam pertanyaan talent profile (§27).
     *
     * @return array<string, mixed>
     */
    public function profile(int $employeeId): array
    {
        $portfolio = $this->growthPortfolio($employeeId);
        if (($portfolio['found'] ?? false) === false) {
            return ['employee_id' => $employeeId, 'found' => false];
        }

        $employee = HrEmployee::query()->find($employeeId);
        $gap = $employee?->position_id ? $this->skills->gapForEmployee($employeeId, 'POSITION', $employee->position_id) : ['gaps' => [], 'summary' => []];
        $training = $this->skills->learningDemand([]);

        return [
            'employee_id' => $employeeId,
            'found' => true,
            'what_they_can_do' => array_slice(array_values(array_filter(array_map(
                fn (array $row): ?string => ($row['confidence'] ?? 0) >= 60 ? ($row['name'].' ('.$row['level'].')') : null,
                $portfolio['skills']
            ))), 0, 8),
            'what_they_are_developing' => array_values(array_map(fn (array $row): string => (string) $row['skill_code'], array_slice($gap['gaps'], 0, 5))),
            'what_they_want_next' => [
                'aspirations' => $portfolio['career_aspirations'],
                'interests' => $portfolio['interests'],
                'talent_review_aspiration' => $this->facts->talentReview($employeeId)['career_aspiration'] ?? null,
            ],
            'positions_that_fit' => $this->positionsThatFit($employeeId),
            'skills_still_missing' => array_map(fn (array $row): array => [
                'skill' => $row['skill_code'], 'missing_levels' => $row['gap'], 'priority' => $row['priority'],
            ], $gap['gaps']),
            'relevant_training' => array_slice($training['rows'] ?? [], 0, 5),
            'readiness' => $gap['summary'] ?? [],
            'profile_completeness' => [
                'skills' => count($portfolio['skills']),
                'certifications' => count($portfolio['certifications']),
                'learning' => count($portfolio['learning']['completions']),
                'evidence_verified' => (int) ($portfolio['skill_lifecycle']['CURRENT'] ?? 0),
            ],
            'evidence_note' => 'Profil disusun dari data nyata lintas modul; angka tanpa bukti selalu ditandai.',
        ];
    }

    /**
     * Segmentasi configurable (§40) — sinyal untuk ditinjau, bukan label final.
     *
     * @return array<string, mixed>
     */
    public function segment(int $employeeId): array
    {
        $performance = $this->facts->performanceScore($employeeId);
        $potential = $this->facts->potentialScore($employeeId);
        $review = $this->facts->talentReview($employeeId);
        $criticalCodes = $this->skills->criticalSkills()['codes'] ?? [];
        $held = HrEmployeeSkill::query()->where('employee_id', $employeeId)->with('skill')->get()
            ->map(fn (HrEmployeeSkill $row): string => (string) $row->skill?->code)->all();
        $criticalHeld = array_values(array_intersect($held, $criticalCodes));
        $retention = $this->ctx->safe(fn () => DB::table('hr_retention_signals')->where('employee_id', $employeeId)->orderByDesc('id')->first(), null);
        $pipeline = $this->ctx->safe(fn () => DB::table('hr_leadership_pipeline')->where('employee_id', $employeeId)->orderByDesc('id')->first(), null);
        $inPool = (int) $this->ctx->safe(fn (): int => HrTalentPoolMember::query()->where('employee_id', $employeeId)->count(), 0);

        $segments = [];
        $evidence = [];
        if (($potential ?? 0) >= 4.0 && ($performance ?? 0) >= 3.5) {
            $segments[] = 'HIGH_POTENTIAL';
            $evidence['HIGH_POTENTIAL'] = ['potential' => $potential, 'performance' => $performance];
        }
        if (($performance ?? 0) >= 4.0) {
            $segments[] = 'HIGH_PERFORMER';
            $evidence['HIGH_PERFORMER'] = ['performance' => $performance];
        }
        if ($criticalHeld !== []) {
            $segments[] = 'CRITICAL_SPECIALIST';
            $segments[] = 'SUBJECT_MATTER_EXPERT';
            $evidence['CRITICAL_SPECIALIST'] = ['critical_skills' => $criticalHeld];
        }
        if ($pipeline) {
            $segments[] = ($pipeline->stage ?? '') === 'EMERGING_LEADER' ? 'EMERGING_LEADER' : 'FUTURE_MANAGER';
            $evidence['LEADERSHIP'] = ['stage' => $pipeline->stage ?? null];
        }
        if ($inPool > 0) {
            $segments[] = 'KEY_TALENT';
            $evidence['KEY_TALENT'] = ['talent_pools' => $inPool];
        }
        if (($retention->signal_level ?? 'LOW') === 'HIGH') {
            $segments[] = 'AT_RISK';
            $evidence['AT_RISK'] = ['retention_score' => (float) ($retention->retention_score ?? 0), 'factors' => json_decode((string) ($retention->factors ?? '[]'), true)];
        }
        if ($segments === []) {
            $segments[] = 'CORE_CONTRIBUTOR';
        }

        return [
            'employee_id' => $employeeId,
            'segments' => array_values(array_unique($segments)),
            'evidence' => $evidence,
            'review' => [
                'nine_box' => $review['nine_box_code'] ?? null, 'readiness' => $review['readiness'] ?? null,
                'risk_of_loss' => $review['risk_of_loss'] ?? null,
            ],
            'note' => 'Segmen adalah sinyal untuk ditinjau, bukan label permanen.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function segments(?int $organizationId = null, int $limit = 200): array
    {
        $employees = HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->limit($limit)->pluck('id');

        $counts = [];
        $rows = [];
        foreach ($employees as $employeeId) {
            $segment = $this->segment((int) $employeeId);
            foreach ($segment['segments'] as $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
            $rows[] = ['employee_id' => (int) $employeeId, 'segments' => $segment['segments']];
        }
        arsort($counts);

        return ['employees' => count($rows), 'by_segment' => $counts, 'rows' => array_slice($rows, 0, 50)];
    }

    /**
     * Talent matrix configurable (§39).
     *
     * @return array<string, mixed>
     */
    public function matrix(string $xAxis = 'PERFORMANCE', string $yAxis = 'POTENTIAL', ?int $organizationId = null, int $limit = 200): array
    {
        $employees = HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->limit($limit)->get(['id', 'employee_number', 'department_id']);

        $cells = [];
        foreach ($employees as $employee) {
            $x = $this->axisValue($xAxis, (int) $employee->getKey());
            $y = $this->axisValue($yAxis, (int) $employee->getKey());
            $cells[] = [
                'employee_id' => (int) $employee->getKey(), 'employee_number' => $employee->employee_number,
                'department_id' => $employee->department_id, 'x' => $x, 'y' => $y, 'box' => $this->boxCode($x, $y),
            ];
        }

        return [
            'axes' => ['x' => strtoupper($xAxis), 'y' => strtoupper($yAxis)],
            'cells' => $cells,
            'distribution' => collect($cells)->groupBy('box')->map->count()->all(),
            'note' => 'Matriks dapat dikonfigurasi: performance × potential, performance × skill, potential × readiness, criticality × succession risk.',
        ];
    }

    /**
     * Internal mobility intelligence (§30).
     *
     * @return array<string, mixed>
     */
    public function mobilityIntelligence(?int $organizationId = null, int $limit = 25): array
    {
        $employees = HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereNotNull('position_id')->limit($limit)->get();

        $rows = [];
        foreach ($employees as $employee) {
            $readiness = $this->readiness((int) $employee->getKey());
            if (($readiness['score'] ?? 0) < 40) {
                continue;
            }
            $rows[] = [
                'employee_id' => (int) $employee->getKey(), 'current_position_id' => $employee->position_id,
                'readiness_score' => $readiness['score'], 'readiness_level' => $readiness['level'],
                'potential_positions' => $readiness['targets'], 'development_required' => $readiness['development'],
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['readiness_score'] <=> $a['readiness_score']);

        return ['rows' => $rows, 'count' => count($rows), 'note' => 'Kandidat untuk ditinjau HR/atasan, bukan keputusan otomatis.'];
    }

    /**
     * Estimasi kesiapan (dipakai karier, suksesi, dan marketplace).
     *
     * @return array<string, mixed>
     */
    public function readiness(int $employeeId, ?int $targetPositionId = null): array
    {
        $facts = $this->facts->snapshot($employeeId);
        $gap = $targetPositionId ? $this->skills->gapForEmployee($employeeId, 'POSITION', $targetPositionId) : ['gaps' => [], 'summary' => []];
        $targets = $targetPositionId ? [$targetPositionId] : array_column($this->positionsThatFit($employeeId), 'position_id');

        $skillsScore = ($gap['summary']['requirements'] ?? 0) > 0
            ? max(0.0, 100 - min(100, (($gap['summary']['total_gap_levels'] ?? 0) / max((int) $gap['summary']['requirements'], 1)) * 40))
            : 60.0;
        $performanceScore = min(100.0, ((($facts['performance_score'] ?? 3.0)) / 5) * 100);
        $competency = empty($facts['competencies']) ? 60.0 : min(100.0, (float) collect($facts['competencies'])
            ->map(fn (array $row): float => (float) ($row['level'] ?? 0) / max((float) ($row['target'] ?? 4), 1) * 100)->avg());
        $experience = min(100.0, ($facts['tenure_months'] / 60) * 100);
        $aspiration = $targetPositionId ? 90.0 : 60.0;
        $development = min(100.0, (float) $facts['learning_participation']);
        $certification = min(100.0, count($facts['certifications']) * 25);
        $weights = (array) $this->ctx->config('succession.readiness_weights', [
            'skills' => 0.25, 'performance' => 0.20, 'competency' => 0.15, 'experience' => 0.15,
            'aspiration' => 0.10, 'development' => 0.10, 'certification' => 0.05,
        ]);

        $score = $this->ctx->score(
            $skillsScore * ($weights['skills'] ?? 0.25) + $performanceScore * ($weights['performance'] ?? 0.20)
            + $competency * ($weights['competency'] ?? 0.15) + $experience * ($weights['experience'] ?? 0.15)
            + $aspiration * ($weights['aspiration'] ?? 0.10) + $development * ($weights['development'] ?? 0.10)
            + $certification * ($weights['certification'] ?? 0.05)
        );

        return [
            'employee_id' => $employeeId, 'target_position_id' => $targetPositionId,
            'score' => $score, 'level' => $this->readinessLevel($score),
            'components' => [
                'skills' => $this->ctx->score($skillsScore), 'performance' => $this->ctx->score($performanceScore),
                'competency' => $this->ctx->score($competency), 'experience' => $this->ctx->score($experience),
                'aspiration' => $aspiration, 'development' => $this->ctx->score($development),
                'certification' => $this->ctx->score($certification),
            ],
            'development' => array_map(fn (array $row): string => (string) $row['skill_code'], array_slice($gap['gaps'], 0, 6)),
            'targets' => $targets,
            'evidence' => ['gap_summary' => $gap['summary'], 'facts' => [
                'performance' => $facts['performance_score'], 'potential' => $facts['potential_score'],
                'tenure_months' => $facts['tenure_months'], 'certifications' => count($facts['certifications']),
            ]],
            'methodology' => 'Bobot dapat dikonfigurasi; skor bukan kebenaran absolut.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function positionsThatFit(int $employeeId, int $limit = 5): array
    {
        return $this->ctx->safe(function () use ($employeeId, $limit): array {
            $employee = HrEmployee::query()->find($employeeId);
            $skills = HrEmployeeSkill::query()->where('employee_id', $employeeId)->with('skill')->get()
                ->map(fn (HrEmployeeSkill $row): string => (string) $row->skill?->code)->all();
            if ($skills === []) {
                return [];
            }

            $rows = DB::table('hr_position_skill_requirements as r')
                ->join('hr_positions as p', 'p.id', '=', 'r.position_id')
                ->whereIn('r.skill_code', $skills)
                ->when($employee?->department_id, fn ($q) => $q->where('p.department_id', '!=', $employee->department_id))
                ->selectRaw('p.id as position_id, p.title as title, count(*) as matched_skills')
                ->groupBy('p.id', 'p.title')->orderByDesc('matched_skills')->limit($limit)->get();

            return $rows->map(fn ($row): array => [
                'position_id' => (int) $row->position_id, 'title' => $row->title,
                'matched_skills' => (int) $row->matched_skills,
                'fit_score' => $this->ctx->score(min(100, $this->ctx->percent((int) $row->matched_skills, max(count($skills), 1)) + 40)),
            ])->all();
        }, []);
    }

    /**
     * @return array<string, mixed>
     */
    public function poolMembership(int $employeeId): array
    {
        return $this->ctx->safe(fn (): array => HrTalentPoolMember::query()->where('employee_id', $employeeId)->with('pool')->get()
            ->map(fn (HrTalentPoolMember $row): array => [
                'pool' => $row->pool?->name, 'readiness' => $row->readiness, 'rank' => $row->rank, 'status' => $row->status,
            ])->all(), []);
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewResult(int $employeeId): array
    {
        $row = $this->ctx->safe(fn () => HrTalentReviewResult::query()->where('employee_id', $employeeId)->orderByDesc('id')->first(), null);

        return $row ? [
            'performance_rating' => (float) $row->performance_rating, 'potential_rating' => (float) $row->potential_rating,
            'nine_box' => $row->nine_box_code, 'readiness' => $row->readiness, 'risk_of_loss' => $row->risk_of_loss,
            'impact_of_loss' => $row->impact_of_loss, 'career_aspiration' => $row->career_aspiration,
        ] : ['found' => false];
    }

    private function axisValue(string $axis, int $employeeId): float
    {
        return match (strtoupper($axis)) {
            'PERFORMANCE' => (float) ($this->facts->performanceScore($employeeId) ?? 0),
            'POTENTIAL' => (float) ($this->facts->potentialScore($employeeId) ?? 0),
            'SKILL' => (float) HrEmployeeSkill::query()->where('employee_id', $employeeId)->where('verified', true)->count(),
            'READINESS' => (float) ($this->readiness($employeeId)['score'] ?? 0),
            default => 0.0,
        };
    }

    private function boxCode(float $x, float $y): string
    {
        $xBand = $x >= 4 ? 'HIGH' : ($x >= 3 ? 'MID' : 'LOW');
        $yBand = $y >= 4 ? 'HIGH' : ($y >= 2.5 ? 'MID' : 'LOW');

        return $yBand.'_'.$xBand;
    }

    private function readinessLevel(float $score): string
    {
        foreach ((array) $this->ctx->config('succession.readiness_thresholds', []) as $level => $threshold) {
            if ($score >= (float) $threshold) {
                return (string) $level;
            }
        }

        return 'NOT_READY';
    }
}
