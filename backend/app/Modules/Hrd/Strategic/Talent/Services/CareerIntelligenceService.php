<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Talent\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeSkill;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Strategic\Skills\Services\SkillsIntelligenceService;
use App\Modules\Hrd\Strategic\Support\PeopleFacts;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use Illuminate\Support\Facades\DB;

/**
 * Career Intelligence: simulasi karier ("apa yang saya perlukan untuk menjadi
 * Kepala Departemen?"), jalur karier bercabang (§32), rencana pengembangan,
 * dan mobilitas internal.
 */
class CareerIntelligenceService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly PeopleFacts $facts,
        private readonly SkillsIntelligenceService $skills,
        private readonly TalentIntelligenceService $talent,
    ) {}

    /**
     * Simulasi karier (§31): profil sekarang → posisi target → gap keterampilan
     * → gap pengalaman → pelatihan → rencana pengembangan → estimasi kesiapan.
     *
     * @return array<string, mixed>
     */
    public function simulate(int $employeeId, int $targetPositionId): array
    {
        $employee = HrEmployee::query()->with('position')->find($employeeId);
        $target = HrPosition::query()->with(['job', 'department'])->find($targetPositionId);
        if (! $employee || ! $target) {
            return ['found' => false, 'reason' => 'Pegawai atau posisi target tidak ditemukan.'];
        }

        $gap = $this->skills->gapForEmployee($employeeId, 'POSITION', $targetPositionId);
        $readiness = $this->talent->readiness($employeeId, $targetPositionId);
        $facts = $this->facts->snapshot($employeeId);

        $requiredExperience = (float) ($target->meta['min_experience_months'] ?? 24);
        $experienceGap = max($requiredExperience - (float) $facts['tenure_months'], 0);
        $requiredCertification = $target->meta['required_certification'] ?? null;
        $certificationGap = $requiredCertification
            ? (count(array_filter($facts['certifications'], fn (array $row): bool => str_contains(mb_strtolower((string) $row['name']), mb_strtolower((string) $requiredCertification)))) === 0)
            : false;

        $development = $this->developmentPlan($employeeId, $targetPositionId);

        return [
            'found' => true,
            'employee' => ['id' => $employee->getKey(), 'position' => $employee->position?->title],
            'target' => ['id' => $target->getKey(), 'title' => $target->title, 'department' => $target->department?->name],
            'skill_gap' => $gap['gaps'],
            'skill_gap_summary' => $gap['summary'],
            'experience_gap' => [
                'required_months' => $requiredExperience, 'current_months' => $facts['tenure_months'],
                'missing_months' => $experienceGap,
            ],
            'certification_gap' => [
                'required' => $requiredCertification, 'missing' => $certificationGap,
                'evidence' => array_column($facts['certifications'], 'name'),
            ],
            'recommended_learning' => array_slice($development['actions'], 0, 6),
            'recommended_projects' => $this->ctx->safe(fn (): array => DB::table('hr_talent_opportunities')
                ->whereIn('status', ['OPEN', 'MATCHING'])
                ->whereRaw('(select count(*) from hr_opportunity_requirements r where r.opportunity_id = hr_talent_opportunities.id
                            and r.key in ('.implode(',', array_fill(0, max(count($gap['gaps']), 1), '?')).')) > 0',
                    array_column($gap['gaps'], 'skill_code') ?: ['-'])
                ->limit(3)->get(['code', 'title'])
                ->map(fn ($row): array => ['code' => $row->code, 'title' => $row->title])->all(), []),
            'mentor_suggestion' => $this->mentorSuggestion($targetPositionId),
            'estimated_readiness' => [
                'score' => $readiness['score'], 'level' => $readiness['level'],
                'components' => $readiness['components'],
            ],
            'career_plan' => $development,
            'evidence' => [
                'data_source' => 'hr_employee_skills, hr_position_skill_requirements, performance, learning, projects',
                'methodology' => 'Bobot kesiapan dari konfigurasi; selalu disertai bukti.',
            ],
        ];
    }

    /**
     * Jalur karier bercabang (§32): dari langkah karier Phase 2 dan hierarki posisi.
     *
     * @return array<string, mixed>
     */
    public function paths(?int $fromPositionId = null, int $depth = 3): array
    {
        $steps = $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_career_path_steps')
            ? DB::table('hr_career_path_steps')->get()->map(fn ($row): array => array_filter([
                'path_id' => $row->path_id ?? null,
                'from_position_id' => $row->from_position_id ?? null,
                'to_position_id' => $row->to_position_id ?? null,
                'from_job_id' => $row->from_job_id ?? null,
                'to_job_id' => $row->to_job_id ?? null,
                'sequence' => $row->sequence ?? null,
            ], fn ($v): bool => $v !== null))->all() : [], []);

        $linear = [];
        if ($fromPositionId) {
            $current = $fromPositionId;
            for ($level = 0; $level < $depth; $level++) {
                $step = collect($steps)->first(fn (array $row): bool => (int) ($row['from_position_id'] ?? 0) === $current);
                if (! $step || empty($step['to_position_id'])) {
                    break;
                }
                $linear[] = ['position_id' => (int) $step['to_position_id'], 'level' => $level + 1];
                $current = (int) $step['to_position_id'];
            }
        }

        $branches = $fromPositionId
            ? collect($steps)->filter(fn (array $row): bool => (int) ($row['from_position_id'] ?? 0) === $fromPositionId)->count()
            : 0;

        $alternative = $this->ctx->safe(fn (): array => HrPosition::query()
            ->when($fromPositionId, function ($query) use ($fromPositionId): void {
                $jump = HrPosition::query()->find($fromPositionId);
                $query->where('department_id', '!=', $jump?->department_id)->whereNotNull('parent_position_id');
            })
            ->limit(3)->get(['id', 'title', 'department_id'])
            ->map(fn (HrPosition $row): array => ['position_id' => $row->getKey(), 'title' => $row->title, 'path' => 'ALTERNATIVE'])
            ->all(), []);

        return [
            'from_position_id' => $fromPositionId,
            'linear_route' => $linear,
            'branches' => $branches,
            'alternative_routes' => $alternative,
            'note' => 'Jalur karier tidak harus linear: spesialis juga jalur yang setara dengan manajerial.',
            'steps_available' => count($steps),
        ];
    }

    /**
     * Rencana pengembangan berurutan dengan estimasi waktu.
     *
     * @return array<string, mixed>
     */
    public function developmentPlan(int $employeeId, int $targetPositionId): array
    {
        $gap = $this->skills->gapForEmployee($employeeId, 'POSITION', $targetPositionId);
        $actions = [];
        $month = 0;

        foreach (array_slice($gap['gaps'], 0, 8) as $row) {
            $months = $row['gap'] >= 2 ? 3 : 2;
            $month += 1;
            $actions[] = [
                'sequence' => count($actions) + 1,
                'skill' => $row['skill_code'], 'priority' => $row['priority'],
                'action' => $row['development_options'][0]['title'] ?? ('Pengembangan '.$row['skill_code']),
                'type' => 'TRAINING', 'start_month' => $month, 'duration_months' => $months,
                'estimated_cost' => (float) $row['estimated_cost'],
                'measurement' => 'Level keterampilan naik ≥ 1 dan bukti tercatat',
            ];
        }

        if ($actions === []) {
            $actions[] = [
                'sequence' => 1, 'skill' => null, 'priority' => 'LOW',
                'action' => 'Pertahankan kinerja & ambil penugasan lintas fungsi',
                'type' => 'EXPERIENCE', 'start_month' => 1, 'duration_months' => 3,
                'estimated_cost' => 0.0, 'measurement' => 'Umpan balik atasan positif',
            ];
        }

        return [
            'employee_id' => $employeeId, 'target_position_id' => $targetPositionId,
            'actions' => $actions, 'total_months' => max(array_map(fn (array $row): int => $row['start_month'] + $row['duration_months'], $actions)),
            'total_cost' => $this->ctx->money(array_sum(array_column($actions, 'estimated_cost'))),
            'note' => 'Rencana dapat dieksekusi melalui permintaan pelatihan Phase 2 dan penugasan marketplace.',
        ];
    }

    /**
     * Simpan/perbarui rencana karier pegawai (memakai tabel karier Phase 2).
     */
    public function plan(int $employeeId, int $targetPositionId, mixed $actor = null, array $options = []): array
    {
        $simulation = $this->simulate($employeeId, $targetPositionId);
        $employee = HrEmployee::query()->find($employeeId);

        $row = $this->ctx->safe(fn () => DB::table('hr_employee_career_plans')->updateOrInsert(
            ['employee_id' => $employeeId, 'target_position_id' => $targetPositionId],
            [
                'current_position_id' => $employee?->position_id,
                'readiness_score' => $simulation['estimated_readiness']['score'] ?? 0,
                'readiness_level' => $simulation['estimated_readiness']['level'] ?? 'NOT_READY',
                'gap_snapshot' => json_encode($simulation['skill_gap'] ?? []),
                'status' => $options['status'] ?? 'ACTIVE',
                'notes' => $options['notes'] ?? null,
                'updated_at' => now(), 'created_at' => now(),
            ]
        ), null);

        $this->ctx->audit('strategic.career_plan.saved', HrEmployee::class, $employeeId, [
            'target_position_id' => $targetPositionId,
            'readiness' => $simulation['estimated_readiness']['level'] ?? null,
            'gap_count' => count($simulation['skill_gap'] ?? []),
        ], null, $actor, $employeeId);

        return ['saved' => true, 'simulation' => $simulation, 'record' => $row];
    }

    /**
     * Mobilitas internal: siapa siap pindah, ke mana, dan apa yang kurang.
     *
     * @return array<string, mixed>
     */
    public function internalMobility(?int $organizationId = null, int $limit = 20): array
    {
        $rows = [];
        foreach (HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->limit($limit)->get() as $employee) {
            $fit = $this->talent->positionsThatFit((int) $employee->getKey(), 3);
            if ($fit === []) {
                continue;
            }
            $best = $fit[0];
            $gap = $this->skills->gapForEmployee((int) $employee->getKey(), 'POSITION', (int) $best['position_id']);
            $rows[] = [
                'employee_id' => (int) $employee->getKey(), 'employee_number' => $employee->employee_number,
                'current_position_id' => $employee->position_id,
                'suggested_positions' => $fit,
                'skill_gap' => array_map(fn (array $row): array => ['skill' => $row['skill_code'], 'gap' => $row['gap']], $gap['gaps']),
                'readiness_level' => $this->talent->readiness((int) $employee->getKey(), (int) $best['position_id'])['level'],
            ];
        }

        return ['rows' => $rows, 'count' => count($rows), 'note' => 'Daftar ini bahan pertimbangan, bukan keputusan mutasi.'];
    }

    /**
     * Saran mentor: pegawai di posisi target (atau satu level di atasnya).
     *
     * @return array<string, mixed>|null
     */
    private function mentorSuggestion(int $targetPositionId): ?array
    {
        return $this->ctx->safe(function () use ($targetPositionId): ?array {
            $position = HrPosition::query()->find($targetPositionId);
            $holder = HrEmployee::query()->where('position_id', $targetPositionId)->where('is_active', true)->first()
                ?? ($position?->parent_position_id ? HrEmployee::query()->where('position_id', $position->parent_position_id)->where('is_active', true)->first() : null);

            return $holder ? [
                'employee_id' => $holder->getKey(), 'employee_number' => $holder->employee_number,
                'reason' => 'Menjabat posisi target/level di atasnya; dapat menjadi mentor dan sumber bukti.',
            ] : null;
        }, null);
    }
}
