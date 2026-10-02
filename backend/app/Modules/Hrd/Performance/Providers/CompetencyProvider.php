<?php

namespace App\Modules\Hrd\Performance\Providers;

use App\Models\User;
use App\Modules\Hrd\Application\Services\CompetencyService as Phase1CompetencyService;
use App\Modules\Hrd\Core\Domain\Contracts\CompetencyProviderInterface;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Performance\Models\HrCompetencyCatalog;
use App\Modules\Hrd\Performance\Services\PerformanceService;

/**
 * Kontrak kompetensi: katalog Phase 2 + penilaian, dengan keterampilan Phase 1
 * sebagai fondasi yang sama.
 */
class CompetencyProvider implements CompetencyProviderInterface
{
    public function __construct(
        private readonly PerformanceService $performance,
        private readonly Phase1CompetencyService $skills,
    ) {}

    public function catalog(?string $category = null): array
    {
        return HrCompetencyCatalog::query()
            ->with('levels')
            ->where('is_active', true)
            ->when($category !== null, fn ($query) => $query->where('category', strtoupper($category)))
            ->get()
            ->map(fn (HrCompetencyCatalog $competency) => [
                'code' => $competency->code,
                'name' => $competency->name,
                'category' => $competency->category,
                'max_level' => (int) $competency->max_level,
                'levels' => $competency->levels->map(fn ($level) => [
                    'level' => (int) $level->level,
                    'label' => $level->label,
                    'indicators' => $level->behavioral_indicators,
                ])->all(),
            ])
            ->all();
    }

    public function assess(int $employeeId, array $payload, int $actorUserId): array
    {
        $employee = HrEmployee::query()->findOrFail($employeeId);
        $actor = User::query()->findOrFail($actorUserId);

        $assessment = $this->performance->assessCompetency($employee, $payload, $actor);

        return [
            'assessment_id' => $assessment->getKey(),
            'current_level' => (int) $assessment->current_level,
            'target_level' => (int) $assessment->target_level,
            'gap' => (int) $assessment->gap,
        ];
    }

    public function gap(int $employeeId, ?int $positionId = null): array
    {
        return $this->performance->competencyGaps($employeeId);
    }

    public function skillsFor(int $employeeId): array
    {
        return $this->skills->skillsFor($employeeId);
    }
}
