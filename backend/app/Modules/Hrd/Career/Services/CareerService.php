<?php

namespace App\Modules\Hrd\Career\Services;

use App\Models\User;
use App\Modules\Hrd\Application\Services\LifecycleTransactionService;
use App\Modules\Hrd\Core\Domain\Contracts\CareerProviderInterface;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Career\Models\HrCareerPath;
use App\Modules\Hrd\Career\Models\HrCareerPathStep;
use App\Modules\Hrd\Career\Models\HrEmployeeCareerPlan;
use App\Modules\Hrd\Career\Models\HrInternalJobApplication;
use App\Modules\Hrd\Career\Models\HrMentorAssignment;
use App\Modules\Hrd\Career\Models\HrMentoringProgram;
use App\Modules\Hrd\Career\Models\HrMentoringSession;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Performance\Models\HrPositionSkillRequirement;
use App\Modules\Hrd\Performance\Services\PerformanceService;
use App\Modules\Hrd\Recruitment\Models\HrJobOpening;
use App\Modules\Hrd\Recruitment\Services\CandidatePipelineService;
use Illuminate\Support\Facades\DB;

/**
 * Karier: jalur, kesenjangan keterampilan, rencana pengembangan, dan mobilitas
 * internal.
 *
 * Pegawai internal **tidak** membuat profil kandidat baru: ia memakai baris
 * lamaran yang sama pada corong rekrutmen dengan penanda pegawai internal.
 * Bila terpilih, perpindahannya dijalankan oleh mesin transaksi Phase 1 —
 * sehingga riwayat posisi, formasi, dan bagan organisasi tetap konsisten.
 */
class CareerService implements CareerProviderInterface
{
    public function __construct(
        private readonly PerformanceService $performance,
        private readonly CandidatePipelineService $pipeline,
        private readonly LifecycleTransactionService $transactions,
        private readonly HrdPhase2Events $events,
    ) {}

    /** @param array<string, mixed> $attributes @param list<array<string, mixed>> $steps */
    public function createPath(array $attributes, User $actor, array $steps = []): HrCareerPath
    {
        return DB::transaction(function () use ($attributes, $steps) {
            $path = HrCareerPath::query()->updateOrCreate(
                ['code' => strtoupper((string) $attributes['code'])],
                [
                    'name' => $attributes['name'] ?? $attributes['code'],
                    'job_family_id' => $attributes['job_family_id'] ?? null,
                    'description' => $attributes['description'] ?? null,
                ],
            );

            foreach ($steps as $index => $step) {
                HrCareerPathStep::query()->updateOrCreate(
                    ['path_id' => $path->getKey(), 'sequence' => (int) ($step['sequence'] ?? $index + 1)],
                    [
                        'position_id' => $step['position_id'] ?? null,
                        'job_id' => $step['job_id'] ?? null,
                        'grade_code' => $step['grade_code'] ?? null,
                        'min_years_experience' => $step['min_years_experience'] ?? null,
                        'required_competencies' => $step['required_competencies'] ?? null,
                        'required_skills' => $step['required_skills'] ?? null,
                    ],
                );
            }

            return $path->refresh();
        });
    }

    /** @return array<string, mixed> */
    public function pathFor(int $positionId): array
    {
        $step = HrCareerPathStep::query()->where('position_id', $positionId)->orderBy('sequence')->first();

        if ($step === null) {
            return ['path' => null, 'current_sequence' => null, 'next_steps' => []];
        }

        $steps = HrCareerPathStep::query()
            ->with('path')
            ->where('path_id', $step->path_id)
            ->orderBy('sequence')
            ->get()
            ->map(fn (HrCareerPathStep $item) => [
                'sequence' => (int) $item->sequence,
                'position_id' => $item->position_id,
                'grade_code' => $item->grade_code,
                'min_years_experience' => $item->min_years_experience !== null ? (float) $item->min_years_experience : null,
                'required_skills' => $item->required_skills,
            ])
            ->all();

        $next = array_values(array_filter($steps, fn (array $item) => (int) $item['sequence'] > (int) $step->sequence));

        return [
            'path' => $step->path?->name,
            'path_code' => $step->path?->code,
            'current_sequence' => (int) $step->sequence,
            'next_steps' => $next,
        ];
    }

    /**
     * Kesiapan pegawai menuju posisi target, dihitung dari keterampilan yang
     * dimiliki (Phase 1) dan kesenjangan kompetensi (Phase 2).
     *
     * @return array<string, mixed>
     */
    public function readiness(int $employeeId, int $targetPositionId): array
    {
        $requirements = HrPositionSkillRequirement::query()
            ->where(fn ($query) => $query->where('position_id', $targetPositionId)->orWhereNull('position_id'))
            ->get();

        $levels = ['BEGINNER' => 1, 'INTERMEDIATE' => 2, 'ADVANCED' => 3, 'EXPERT' => 4];

        $rank = [
            'BEGINNER' => 1, 'INTERMEDIATE' => 2, 'ADVANCED' => 3, 'EXPERT' => 4,
        ];

        $owned = app(\App\Modules\Hrd\Application\Services\CompetencyService::class)->skillsFor($employeeId);
        $ownedMap = [];

        foreach ($owned as $skill) {
            $code = strtoupper((string) ($skill['skill_code'] ?? ''));
            $ownedMap[$code] = $rank[strtoupper((string) ($skill['proficiency_level_code'] ?? 'BEGINNER'))] ?? 1;
        }

        $gaps = [];
        $met = 0;

        foreach ($requirements as $requirement) {
            $code = strtoupper((string) $requirement->skill_code);
            $required = $rank[strtoupper((string) $requirement->required_level)] ?? 1;
            $has = $ownedMap[$code] ?? 0;

            if ($has >= $required) {
                $met++;

                continue;
            }

            $gaps[] = [
                'skill_code' => $code,
                'required_level' => $requirement->required_level,
                'current_level' => $has === 0 ? null : array_search($has, $rank, true),
                'mandatory' => (bool) $requirement->is_mandatory,
            ];
        }

        $competency = $this->performance->competencyGaps($employeeId);
        $skillScore = $requirements->isEmpty() ? 100.0 : round($met / $requirements->count() * 100, 2);
        $readinessScore = round(($skillScore * 0.7) + (max(0, 100 - (int) $competency['total_gap'] * 10) * 0.3), 2);

        $level = match (true) {
            $readinessScore >= 85 => 'READY_NOW',
            $readinessScore >= 60 => 'READY_SOON',
            $readinessScore >= 35 => 'READY_1_2_YEARS',
            default => 'LONG_TERM',
        };

        return [
            'employee_id' => $employeeId,
            'target_position_id' => $targetPositionId,
            'skill_score' => $skillScore,
            'competency_gap' => (int) $competency['total_gap'],
            'readiness_score' => $readinessScore,
            'readiness_level' => $level,
            'skill_gaps' => $gaps,
            'competency_gaps' => $competency['items'],
            'recommended_learning' => $this->recommendedTraining($gaps),
        ];
    }

    /**
     * Pelatihan yang disarankan untuk menutup kesenjangan (§66, §70).
     *
     * @param list<array<string, mixed>> $gaps
     * @return list<array<string, mixed>>
     */
    public function recommendedTraining(array $gaps): array
    {
        $codes = array_map(fn (array $gap) => $gap['skill_code'], $gaps);

        if ($codes === []) {
            return [];
        }

        return \App\Modules\Hrd\Learning\Models\HrTrainingCatalog::query()
            ->where('is_active', true)
            ->get()
            ->filter(function ($training) use ($codes) {
                $skills = array_map('strtoupper', array_map(fn ($skill) => is_array($skill) ? ($skill['code'] ?? '') : (string) $skill, (array) $training->skills));

                return array_intersect($codes, $skills) !== [];
            })
            ->map(fn ($training) => [
                'training_code' => $training->code,
                'title' => $training->title,
                'duration_hours' => (float) $training->duration_hours,
                'provider' => $training->provider,
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $attributes */
    public function createPlan(HrEmployee $employee, array $attributes, User $actor): HrEmployeeCareerPlan
    {
        $targetPositionId = (int) ($attributes['target_position_id'] ?? 0);
        $readiness = $targetPositionId > 0 ? $this->readiness((int) $employee->getKey(), $targetPositionId) : null;

        return HrEmployeeCareerPlan::query()->updateOrCreate(
            ['employee_id' => $employee->getKey(), 'target_position_id' => $targetPositionId],
            [
                'target_job_id' => $attributes['target_job_id'] ?? null,
                'path_id' => $attributes['path_id'] ?? null,
                'current_position_id' => $employee->position_id,
                'readiness_score' => $readiness['readiness_score'] ?? null,
                'readiness_level' => $readiness['readiness_level'] ?? 'LONG_TERM',
                'gap_snapshot' => $readiness,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'status' => 'ACTIVE',
                'notes' => $attributes['notes'] ?? null,
            ],
        );
    }

    /** @return array<string, mixed> */
    public function developmentPlan(int $employeeId): array
    {
        $plans = \App\Modules\Hrd\Performance\Models\HrDevelopmentPlan::query()
            ->with('actions')
            ->where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->get();

        $careerPlan = HrEmployeeCareerPlan::query()->where('employee_id', $employeeId)->where('status', 'ACTIVE')->orderByDesc('id')->first();

        return [
            'career_plan' => $careerPlan?->only(['target_position_id', 'readiness_score', 'readiness_level', 'status']),
            'gap_snapshot' => $careerPlan?->gap_snapshot,
            'plans' => $plans->map(fn ($plan) => [
                'code' => $plan->code,
                'title' => $plan->title,
                'status' => $plan->status,
                'progress' => (float) $plan->progress,
                'actions' => $plan->actions->map->only(['action_type', 'title', 'status', 'due_date', 'progress'])->all(),
            ])->all(),
        ];
    }

    /**
     * Melamar posisi internal: satu baris lamaran yang ditautkan ke pegawai,
     * bukan profil kandidat kedua (§69).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function applyInternal(int $employeeId, int $openingId, array $payload, ?int $actorUserId = null): array
    {
        $employee = HrEmployee::query()->findOrFail($employeeId);
        $opening = HrJobOpening::query()->findOrFail($openingId);
        $actor = $actorUserId !== null ? User::query()->findOrFail($actorUserId) : ($employee->user ?? User::query()->first());

        if ($actor === null) {
            throw HrdValidationException::forField('actor', 'Pelaku perubahan wajib diketahui.', 'career.actor_required');
        }

        if (strtoupper((string) $opening->visibility) === 'EXTERNAL') {
            throw HrdValidationException::forField('opening_id', 'Lowongan ini hanya untuk pelamar eksternal.', 'career.internal_not_allowed');
        }

        return DB::transaction(function () use ($employee, $opening, $payload, $actor) {
            $candidate = app(\App\Modules\Hrd\Recruitment\Services\CandidatePipelineService::class)->register([
                'full_name' => $employee->displayName() ?? $employee->employee_number,
                'email' => $employee->person?->work_email ?? ('employee'.$employee->getKey().'@internal.local'),
                'phone' => $employee->person?->mobile,
                'source_code' => 'INTERNAL',
                'skills' => $payload['skills'] ?? null,
            ], $actor);

            $application = $this->pipeline->apply($candidate, $opening->getKey(), $actor, [
                'internal_employee_id' => $employee->getKey(),
                'source_code' => 'INTERNAL',
                'meta' => ['internal' => true, 'current_position_id' => $employee->position_id],
            ]);

            $internal = HrInternalJobApplication::query()->create([
                'code' => sprintf('IJA-%s-%04d', now()->format('Y'), HrInternalJobApplication::query()->whereYear('created_at', now()->year)->count() + 1),
                'employee_id' => $employee->getKey(),
                'opening_id' => $opening->getKey(),
                'position_id' => $opening->position_id,
                'application_id' => $application->getKey(),
                'status' => 'SUBMITTED',
                'readiness_score' => $opening->position_id !== null ? $this->readiness((int) $employee->getKey(), (int) $opening->position_id)['readiness_score'] : null,
                'notes' => $payload['notes'] ?? null,
            ]);

            $this->events->emit('CAREER', 'internal_application.submitted', [
                'actor' => $actor,
                'employee_id' => $employee->getKey(),
                'subject_type' => HrInternalJobApplication::class,
                'subject_id' => $internal->getKey(),
                'title' => 'Melamar posisi internal: '.$opening->title,
                'payload' => ['opening' => $opening->code, 'application' => $application->code, 'manager_notified' => true],
            ]);

            $internal->forceFill(['manager_notified_at' => now(), 'status' => 'MANAGER_NOTIFIED'])->save();

            return [
                'internal_application_id' => $internal->getKey(),
                'application_id' => $application->getKey(),
                'candidate_id' => $candidate->getKey(),
                'status' => $internal->status,
            ];
        });
    }

    /** Pindah posisi setelah terpilih — memakai mesin transaksi Phase 1. */
    public function transfer(HrInternalJobApplication $internal, User $actor, ?string $effectiveDate = null): array
    {
        if ($internal->position_id === null) {
            throw HrdValidationException::forField('position_id', 'Lowongan ini tidak menunjuk posisi tertentu.', 'career.position_missing');
        }

        if (! in_array($internal->status, ['SELECTED', 'MANAGER_NOTIFIED', 'INTERVIEW', 'SCREENING', 'SUBMITTED'], true)) {
            throw HrdValidationException::forField('status', 'Lamaran internal ini tidak dapat dipindahkan.', 'career.status_invalid');
        }

        $employee = HrEmployee::query()->findOrFail($internal->employee_id);

        $transaction = $this->transactions->create($employee, 'TRANSFER', [
            'position_id' => $internal->position_id,
            'organization_id' => $internal->opening?->organization_id,
        ], $actor, [
            'effective_date' => $effectiveDate ?? now()->toDateString(),
            'reason' => 'Mobilitas internal dari lamaran '.$internal->code,
        ]);

        $decided = $this->transactions->approve($transaction->refresh(), $actor, 'Disetujui: mobilitas internal');

        $internal->forceFill([
            'status' => 'TRANSFERRED',
            'hr_decision' => 'APPROVED',
            'transfer_transaction_id' => $decided->getKey(),
        ])->save();

        $this->events->emit('CAREER', 'internal_application.transferred', [
            'actor' => $actor,
            'employee_id' => $employee->getKey(),
            'subject_type' => HrInternalJobApplication::class,
            'subject_id' => $internal->getKey(),
            'title' => 'Perpindahan posisi dijalankan ('.$transaction->transaction_number.')',
            'payload' => ['transaction_id' => $decided->getKey(), 'status' => $decided->status],
        ]);

        return ['internal_application_id' => $internal->getKey(), 'transaction_id' => $decided->getKey(), 'status' => $decided->status];
    }

    /** @param array<string, mixed> $attributes */
    public function createMentoringProgram(array $attributes, User $actor): HrMentoringProgram
    {
        return HrMentoringProgram::query()->create([
            'code' => strtoupper((string) ($attributes['code'] ?? 'MEN-'.now()->format('YmdHis'))),
            'name' => $attributes['name'] ?? 'Program Mentoring',
            'description' => $attributes['description'] ?? null,
            'objective' => $attributes['objective'] ?? null,
            'start_date' => $attributes['start_date'] ?? now()->toDateString(),
            'end_date' => $attributes['end_date'] ?? now()->addMonths(6)->toDateString(),
            'status' => 'ACTIVE',
            'organization_id' => $attributes['organization_id'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function pairMentor(HrMentoringProgram $program, HrEmployee $mentor, HrEmployee $mentee, array $attributes, User $actor): HrMentorAssignment
    {
        return HrMentorAssignment::query()->updateOrCreate(
            ['program_id' => $program->getKey(), 'mentor_employee_id' => $mentor->getKey(), 'mentee_employee_id' => $mentee->getKey()],
            [
                'focus_areas' => $attributes['focus_areas'] ?? null,
                'status' => 'ACTIVE',
                'started_at' => $attributes['started_at'] ?? now()->toDateString(),
            ],
        );
    }

    /** @param array<string, mixed> $attributes */
    public function recordMentoringSession(HrMentorAssignment $assignment, array $attributes, User $actor): HrMentoringSession
    {
        return HrMentoringSession::query()->create([
            'assignment_id' => $assignment->getKey(),
            'session_date' => $attributes['session_date'] ?? now()->toDateString(),
            'duration_minutes' => (int) ($attributes['duration_minutes'] ?? 60),
            'topic' => $attributes['topic'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'action_items' => $attributes['action_items'] ?? null,
            'mentor_notes' => $attributes['mentor_notes'] ?? null,
            'mentee_notes' => $attributes['mentee_notes'] ?? null,
            'status' => strtoupper((string) ($attributes['status'] ?? 'COMPLETED')),
        ]);
    }
}
