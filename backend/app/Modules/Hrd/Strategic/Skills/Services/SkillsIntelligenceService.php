<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeSkill;
use App\Modules\Hrd\Models\HrSkill;
use App\Modules\Hrd\Strategic\Skills\Models\HrEmployeeSkillProfile;
use App\Modules\Hrd\Strategic\Skills\Models\HrPositionSkill;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillAssessment;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillDemand;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillEvidence;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillForecast;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillGap;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillGovernance;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillLevel;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillRelationship;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillRequirement;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Skills Intelligence.
 *
 * Keterampilan pegawai tetap milik Phase 1 (hr_skills, hr_employee_skills).
 * Phase 3 menambahkan taksonomi, ontologi, bukti, keyakinan, peluruhan,
 * gap, permintaan, dan prakiraan — lalu memakainya di rekrutmen, pelatihan,
 * karier, suksesi, dan perencanaan tenaga kerja.
 */
class SkillsIntelligenceService
{
    public function __construct(private readonly Phase3Context $ctx) {}

    /* ---------------------------------------------------------------------
     | Taksonomi & ontologi (§16, §17)
     | -------------------------------------------------------------------- */

    public function defineDomain(array $data): object
    {
        return \App\Modules\Hrd\Strategic\Skills\Models\HrSkillDomain::query()->updateOrCreate(
            ['code' => strtoupper((string) $data['code'])],
            [
                'name' => $data['name'], 'description' => $data['description'] ?? null,
                'parent_id' => $data['parent_id'] ?? null, 'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => true,
            ]
        );
    }

    public function defineCategory(array $data): object
    {
        return \App\Modules\Hrd\Strategic\Skills\Models\HrSkillCategory::query()->updateOrCreate(
            ['code' => strtoupper((string) $data['code'])],
            [
                'name' => $data['name'], 'domain_id' => $data['domain_id'] ?? null,
                'description' => $data['description'] ?? null, 'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => true,
            ]
        );
    }

    /**
     * Level keterampilan dengan tujuan bukti yang diharapkan.
     *
     * @return array<int, HrSkillLevel>
     */
    public function ensureLevels(): array
    {
        $levels = (array) $this->ctx->config('skills.levels', []);
        $rows = [];
        $expectation = [
            'BEGINNER' => 'Mengetahui dasar, membutuhkan pendampingan.',
            'INTERMEDIATE' => 'Mampu bekerja mandiri pada tugas rutin.',
            'ADVANCED' => 'Mampu menangani kasus kompleks dan membimbing orang lain.',
            'EXPERT' => 'Rujukan organisasi, bukti sertifikasi/asesmen dan dampak terukur.',
        ];
        foreach ($levels as $code => $ordinal) {
            if ((int) $ordinal === 0) {
                continue;
            }
            $rows[] = HrSkillLevel::query()->updateOrCreate(
                ['code' => (string) $code],
                [
                    'name' => ucfirst(mb_strtolower((string) $code)), 'ordinal' => (int) $ordinal,
                    'description' => $expectation[$code] ?? null,
                    'evidence_expectation' => ['min_evidence' => (int) $ordinal, 'verified_required' => (int) $ordinal >= 3],
                    'is_active' => true,
                ]
            );
        }

        return $rows;
    }

    public function linkSkills(int $skillId, int $relatedSkillId, string $type, float $strength = 1.0): HrSkillRelationship
    {
        return HrSkillRelationship::query()->updateOrCreate(
            ['skill_id' => $skillId, 'related_skill_id' => $relatedSkillId, 'relationship_type' => strtoupper($type)],
            ['strength' => $strength]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function ontology(int $skillId): array
    {
        $relations = HrSkillRelationship::query()->where('skill_id', $skillId)->orWhere('related_skill_id', $skillId)->get();
        $byType = $relations->groupBy('relationship_type')->map(fn ($rows) => $rows->map(fn (HrSkillRelationship $row): array => [
            'skill_id' => $row->skill_id === $skillId ? $row->related_skill_id : $row->skill_id,
            'type' => $row->relationship_type, 'strength' => (float) $row->strength,
        ])->all())->all();

        return [
            'skill_id' => $skillId,
            'parent' => $byType['PARENT'] ?? [],
            'child' => $byType['CHILD'] ?? [],
            'related' => $byType['RELATED'] ?? [],
            'equivalent' => $byType['EQUIVALENT'] ?? [],
            'prerequisite' => $byType['PREREQUISITE'] ?? [],
            'complementary' => $byType['COMPLEMENTARY'] ?? [],
            'obsolete' => $byType['OBSOLETE'] ?? [],
            'total' => $relations->count(),
        ];
    }

    /**
     * Tarik kebutuhan keterampilan dari Phase 1/2 (position & job requirements)
     * menjadi struktur criticality Phase 3. Tidak menduplikasi master skill.
     *
     * @return array<string, int>
     */
    public function syncRequirementsFromPhase2(): array
    {
        $levels = (array) $this->ctx->config('skills.levels', []);
        $positionCount = $jobCount = 0;

        $this->ctx->safe(function () use ($levels, &$positionCount): void {
            if (! $this->ctx->hasTable('hr_position_skill_requirements')) {
                return;
            }
            foreach (DB::table('hr_position_skill_requirements')->get() as $row) {
                $skillId = DB::table('hr_skills')->where('code', $row->skill_code)->value('id');
                $requirementType = ((bool) $row->is_mandatory) ? 'REQUIRED' : 'PREFERRED';
                HrPositionSkill::query()->updateOrCreate(
                    ['position_id' => $row->position_id, 'skill_code' => $row->skill_code],
                    [
                        'skill_id' => $skillId, 'requirement_type' => $requirementType,
                        'required_level' => $row->required_level,
                        'required_ordinal' => (int) ($levels[strtoupper((string) $row->required_level)] ?? 0),
                        'is_critical' => $requirementType === 'REQUIRED' && (int) ($levels[strtoupper((string) $row->required_level)] ?? 0) >= 3,
                    ]
                );
                HrSkillRequirement::query()->updateOrCreate(
                    ['scope_type' => 'POSITION', 'scope_id' => (int) $row->position_id, 'skill_code' => $row->skill_code],
                    [
                        'skill_id' => $skillId, 'requirement_type' => $requirementType,
                        'required_level' => $row->required_level,
                        'required_level_ordinal' => (int) ($levels[strtoupper((string) $row->required_level)] ?? 0),
                        'weight' => (float) ($row->weight ?? 1), 'source' => 'PHASE2', 'status' => 'ACTIVE',
                    ]
                );
                $positionCount++;
            }
        });

        $this->ctx->safe(function () use ($levels, &$jobCount): void {
            if (! $this->ctx->hasTable('hr_job_skill_requirements')) {
                return;
            }
            foreach (DB::table('hr_job_skill_requirements')->get() as $row) {
                $skillId = DB::table('hr_skills')->where('code', $row->skill_code)->value('id');
                HrPositionSkill::query()->updateOrCreate(
                    ['job_id' => $row->job_id, 'skill_code' => $row->skill_code],
                    [
                        'skill_id' => $skillId, 'requirement_type' => ((bool) ($row->is_mandatory ?? false)) ? 'REQUIRED' : 'PREFERRED',
                        'required_level' => $row->required_level ?? null,
                        'required_ordinal' => (int) ($levels[strtoupper((string) ($row->required_level ?? 'NONE'))] ?? 0),
                    ]
                );
                $jobCount++;
            }
        });

        return ['position_requirements' => $positionCount, 'job_requirements' => $jobCount];
    }

    /* ---------------------------------------------------------------------
     | Bukti, keyakinan, asesmen, peluruhan (§18–§20)
     | -------------------------------------------------------------------- */

    /**
     * Catat bukti keterampilan. Bukti adalah sumber keyakinan: pelatihan,
     * sertifikasi, asesmen, kinerja, proyek, prestasi, endorsement manajer,
     * pengalaman, sertifikat eksternal, penyelesaian LMS.
     */
    public function recordEvidence(int $employeeId, string $skillCode, array $data, mixed $actor = null): HrSkillEvidence
    {
        $skill = HrSkill::query()->where('code', $skillCode)->first();
        $type = strtoupper((string) ($data['evidence_type'] ?? 'EXPERIENCE'));
        $confidenceTable = (array) $this->ctx->config('skills.confidence', []);
        $levels = (array) $this->ctx->config('skills.levels', []);
        $levelOrdinal = (int) ($levels[strtoupper((string) ($data['level'] ?? 'INTERMEDIATE'))] ?? 2);
        // Penilai diterima sebagai employee id (semantik HR). Kolom verified_by
        // pada Phase 1 merujuk ke tabel users, jadi id tersebut dipetakan lebih
        // dulu; bila belum tertaut user, nilainya null dan tetap tercatat di details.
        $verifierEmployeeId = (int) ($data['verified_by'] ?? 0) ?: null;
        $verifiedBy = $verifierEmployeeId
            ? (int) $this->ctx->safe(fn (): ?int => ($userId = HrEmployee::query()->whereKey($verifierEmployeeId)->value('user_id')) ? (int) $userId : null, null) ?: null
            : null;
        $baseConfidence = match ($type) {
            'CERTIFICATION', 'EXTERNAL_CERTIFICATION' => $confidenceTable['CERTIFICATION'] ?? 95,
            'ASSESSMENT' => $confidenceTable['ASSESSMENT'] ?? 85,
            'MANAGER_ENDORSEMENT' => $confidenceTable['MANAGER'] ?? 70,
            'TRAINING', 'LMS_COMPLETION', 'PROJECT', 'PERFORMANCE', 'ACHIEVEMENT' => $confidenceTable['SYSTEM_INFERRED'] ?? 55,
            default => $confidenceTable['SELF'] ?? 40,
        };

        $evidence = HrSkillEvidence::query()->create([
            'employee_id' => $employeeId,
            'skill_id' => $skill?->getKey(),
            'skill_code' => $skillCode,
            'evidence_type' => $type,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'level' => $data['level'] ?? 'INTERMEDIATE',
            'level_ordinal' => $levelOrdinal,
            'confidence' => $baseConfidence,
            'verified_by' => $verifiedBy,
            'verified_at' => $verifiedBy ? now() : null,
            'observed_at' => $data['observed_at'] ?? now()->toDateString(),
            'expiry_date' => $data['expiry_date'] ?? null,
            'weight' => (float) ($data['weight'] ?? 1.0),
            'details' => ($data['details'] ?? []) + ($verifierEmployeeId ? ['verified_by_employee_id' => $verifierEmployeeId] : []),
        ]);

        if ($skill) {
            $existing = HrEmployeeSkill::query()->where('employee_id', $employeeId)->where('skill_id', $skill->getKey())->first();
            $currentOrdinal = (int) ($levels[strtoupper((string) ($existing->proficiency_level_code ?? 'NONE'))] ?? 0);
            $newOrdinal = max($currentOrdinal, $levelOrdinal);
            $levelCode = array_search($newOrdinal, $levels, true) ?: ($existing->proficiency_level_code ?? 'INTERMEDIATE');

            HrEmployeeSkill::query()->updateOrCreate(
                ['employee_id' => $employeeId, 'skill_id' => $skill->getKey()],
                [
                    'proficiency_level_code' => $levelCode,
                    'years_experience' => $existing->years_experience ?? 0,
                    'verified' => $baseConfidence >= (int) ($this->ctx->config('skills.verified_threshold', 70)),
                    'verified_by' => $verifiedBy ?? $existing->verified_by ?? null,
                    'verified_at' => $baseConfidence >= (int) ($this->ctx->config('skills.verified_threshold', 70)) ? now() : $existing->verified_at ?? null,
                    'last_assessed_at' => now()->toDateString(),
                    'source' => $type,
                    'notes' => $data['notes'] ?? $existing->notes ?? null,
                ]
            );
        }

        $this->refreshProfile($employeeId);

        $this->ctx->audit('strategic.skill_evidence.recorded', HrSkillEvidence::class, $evidence->getKey(), [
            'employee_id' => $employeeId, 'skill' => $skillCode, 'type' => $type, 'level' => $evidence->level,
        ], null, $actor, $employeeId);

        return $evidence;
    }

    /**
     * Asesmen keterampilan (self/manager/assessment/certification).
     */
    public function assess(int $employeeId, string $skillCode, string $level, string $method = 'MANAGER', ?int $assessorEmployeeId = null, mixed $actor = null): HrSkillAssessment
    {
        $skill = HrSkill::query()->where('code', $skillCode)->first();
        $evidenceCount = HrSkillEvidence::query()->where('employee_id', $employeeId)->where('skill_code', $skillCode)->count();
        $levels = (array) $this->ctx->config('skills.levels', []);
        $confidence = match (strtoupper($method)) {
            'CERTIFICATION' => 95, 'ASSESSMENT' => 85, 'MANAGER' => 70, 'SELF' => 40, default => 55,
        };

        $assessment = HrSkillAssessment::query()->create([
            'employee_id' => $employeeId,
            'skill_id' => $skill?->getKey(),
            'skill_code' => $skillCode,
            'method' => strtoupper($method),
            'assessed_level' => strtoupper($level),
            'assessed_ordinal' => (int) ($levels[strtoupper($level)] ?? 0),
            'evidence_count' => $evidenceCount,
            'assessor_employee_id' => $assessorEmployeeId,
            'assessed_at' => now(),
            'confidence' => $confidence,
        ]);

        $this->recordEvidence($employeeId, $skillCode, [
            'evidence_type' => strtoupper($method) === 'SELF' ? 'EXPERIENCE' : 'ASSESSMENT',
            'level' => $level,
            'source_type' => HrSkillAssessment::class,
            'source_id' => $assessment->getKey(),
            'verified_by' => in_array(strtoupper($method), ['MANAGER', 'ASSESSMENT', 'CERTIFICATION'], true) ? $assessorEmployeeId : null,
            'details' => ['method' => $method, 'assessor' => $assessorEmployeeId],
        ], $actor);

        return $assessment;
    }

    /**
     * Keyakinan keterampilan: gabungan bukti, verifikasi, dan kesegaran.
     *
     * @return array<string, mixed>
     */
    public function confidenceFor(int $employeeId, string $skillCode): array
    {
        $evidence = HrSkillEvidence::query()->where('employee_id', $employeeId)->where('skill_code', $skillCode)->get();
        $types = $evidence->pluck('evidence_type')->unique()->all();
        $base = $evidence->max('confidence') ?? 0.0;
        $diversity = min(count($types) * 5, 15);
        $latest = $evidence->max('observed_at');
        $monthsOld = $latest ? $this->ctx->monthsBetween($latest, now()) : null;
        $freshness = $monthsOld === null ? 0 : max(0, 15 - min($monthsOld, 15));
        $confidence = min(100, (float) $base + $diversity + $freshness);

        return [
            'skill_code' => $skillCode,
            'employee_id' => $employeeId,
            'confidence' => $this->ctx->score($confidence),
            'evidence_count' => $evidence->count(),
            'evidence_types' => $types,
            'verified' => $evidence->whereNotNull('verified_by')->isNotEmpty(),
            'has_certification' => in_array('CERTIFICATION', $types, true) || in_array('EXTERNAL_CERTIFICATION', $types, true),
            'last_evidence_at' => $latest,
            'note' => 'Keyakinan bukan kebenaran absolut: selalu tampilkan buktinya.',
        ];
    }

    /**
     * Daur hidup keterampilan: current, emerging, stale, expired, obsolete.
     * Tidak pernah menghapus — hanya menandai dan menurunkan keyakinan.
     *
     * @return array<string, mixed>
     */
    public function lifecycle(int $employeeId): array
    {
        $staleAfter = (int) $this->ctx->config('skills.decay.stale_after_months', 18);
        $expiredAfter = (int) $this->ctx->config('skills.decay.expired_after_months', 36);
        $emergingWindow = (int) $this->ctx->config('skills.decay.emerging_window_months', 12);
        $hasSkillUsage = $this->ctx->safe(fn (): bool => $this->ctx->hasTable('hr_skill_evidence'), false);

        $skills = HrEmployeeSkill::query()->where('employee_id', $employeeId)->with('skill')->get();
        $rows = [];
        $counts = ['CURRENT' => 0, 'EMERGING' => 0, 'STALE' => 0, 'EXPIRED' => 0, 'OBSOLETE' => 0];

        foreach ($skills as $employeeSkill) {
            $code = $employeeSkill->skill?->code ?? (string) $employeeSkill->skill_id;
            $latestEvidence = $hasSkillUsage
                ? HrSkillEvidence::query()->where('employee_id', $employeeId)->where('skill_code', $code)->max('observed_at')
                : $employeeSkill->last_assessed_at?->toDateString();
            $months = $latestEvidence ? $this->ctx->monthsBetween($latestEvidence, now()) : 999;
            $obsolete = $this->ctx->safe(fn (): bool => HrSkillRelationship::query()
                ->where('relationship_type', 'OBSOLETE')
                ->where(fn ($q) => $q->where('skill_id', $employeeSkill->skill_id)->orWhere('related_skill_id', $employeeSkill->skill_id))
                ->exists(), false);

            $state = match (true) {
                $obsolete => 'OBSOLETE',
                $months >= $expiredAfter => 'EXPIRED',
                $months >= $staleAfter => 'STALE',
                $months <= $emergingWindow && $employeeSkill->last_assessed_at === null => 'EMERGING',
                default => 'CURRENT',
            };

            $counts[$state]++;
            $rows[] = [
                'skill_code' => $code, 'state' => $state, 'months_since_evidence' => $months === 999 ? null : $months,
                'lifecycle' => $state,
                'lifecycle_state' => $state,
                'confidence_adjustment' => $state === 'CURRENT' ? 0 : ($state === 'STALE' ? -15 : -30),
                'note' => $state === 'STALE' || $state === 'EXPIRED'
                    ? 'Perlu penyegaran bukti/pelatihan; keterampilan tidak dihapus.'
                    : 'Bukti masih segar.',
            ];
        }

        return ['employee_id' => $employeeId, 'skills' => count($rows), 'by_state' => $counts, 'rows' => $rows];
    }

    public function refreshProfile(int $employeeId): HrEmployeeSkillProfile
    {
        $skills = HrEmployeeSkill::query()->where('employee_id', $employeeId)->with('skill')->get();
        $evidenceCount = HrSkillEvidence::query()->where('employee_id', $employeeId)->count();
        $confidences = [];
        $codes = [];

        foreach ($skills as $employeeSkill) {
            $code = $employeeSkill->skill?->code;
            if (! $code) {
                continue;
            }
            $codes[] = $code;
            $confidences[] = (float) ($this->confidenceFor($employeeId, $code)['confidence'] ?? 0);
        }

        $criticalCodes = $this->criticalSkills()['codes'] ?? [];
        $covered = array_intersect($codes, $criticalCodes);
        $lifecycle = $this->lifecycle($employeeId);

        return HrEmployeeSkillProfile::query()->updateOrCreate(
            ['employee_id' => $employeeId],
            [
                'verified_skill_count' => HrEmployeeSkill::query()->where('employee_id', $employeeId)->where('verified', true)->count(),
                'evidence_count' => $evidenceCount,
                'avg_confidence' => $confidences === [] ? 0 : $this->ctx->score(array_sum($confidences) / count($confidences)),
                'critical_skill_coverage' => $criticalCodes === [] ? 0 : $this->ctx->percent(count($covered), count($criticalCodes)),
                'top_skills' => array_slice($codes, 0, 10),
                'stale_skill_count' => (int) (($lifecycle['by_state']['STALE'] ?? 0) + ($lifecycle['by_state']['EXPIRED'] ?? 0)),
                'emerging_skill_count' => (int) ($lifecycle['by_state']['EMERGING'] ?? 0),
                'last_refreshed_at' => now(),
            ]
        );
    }

    /* ---------------------------------------------------------------------
     | Gap, heatmap, keterampilan kritis, supply vs demand (§21–§24, §90–§91)
     | -------------------------------------------------------------------- */

    /**
     * Gap keterampilan pegawai terhadap posisi/job/rencana (§21).
     *
     * @return array{gaps:array<int, array<string, mixed>>, summary:array<string, mixed>}
     */
    public function gapForEmployee(int $employeeId, string $scopeType = 'POSITION', ?int $scopeId = null, ?int $planId = null): array
    {
        $employee = HrEmployee::query()->find($employeeId);
        $scopeId = $scopeId ?: ($scopeType === 'POSITION' ? $employee?->position_id : null);
        if (! $employee || (! $scopeId && $scopeType !== 'PLAN')) {
            return ['gaps' => [], 'summary' => ['reason' => 'Pegawai/posisi tidak ditemukan']];
        }

        $requirements = HrSkillRequirement::query()
            ->where('scope_type', $scopeType)
            ->when($scopeId, fn ($q) => $q->where('scope_id', $scopeId))
            ->where('status', 'ACTIVE')
            ->get();

        if ($requirements->isEmpty() && $scopeType === 'POSITION' && $scopeId) {
            $requirements = collect(DB::table('hr_position_skill_requirements')->where('position_id', $scopeId)->get())
                ->map(fn ($row): HrSkillRequirement => new HrSkillRequirement([
                    'skill_code' => $row->skill_code, 'required_level' => $row->required_level,
                    'requirement_type' => ((bool) $row->is_mandatory) ? 'REQUIRED' : 'PREFERRED',
                    'scope_type' => 'POSITION', 'scope_id' => $scopeId,
                ]));
        }

        $levels = (array) $this->ctx->config('skills.levels', []);
        $rows = [];
        $summary = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'total_gap_levels' => 0];

        foreach ($requirements as $requirement) {
            $code = (string) $requirement->skill_code;
            $requiredOrdinal = (int) ($requirement->required_level_ordinal
                ?? ($levels[strtoupper((string) $requirement->required_level)] ?? 0));
            $employeeSkill = HrEmployeeSkill::query()->where('employee_id', $employeeId)
                ->whereHas('skill', fn ($q) => $q->where('code', $code))->first();
            $currentOrdinal = (int) ($levels[strtoupper((string) ($employeeSkill->proficiency_level_code ?? 'NONE'))] ?? 0);
            $gap = max($requiredOrdinal - $currentOrdinal, 0);
            $priority = $this->priorityFor($requirement->requirement_type, $gap, $requiredOrdinal);
            $summary[strtolower($priority)] = ($summary[strtolower($priority)] ?? 0) + 1;
            $summary['total_gap_levels'] += $gap;

            if ($gap === 0) {
                continue;
            }

            $row = HrSkillGap::query()->updateOrCreate(
                ['employee_id' => $employeeId, 'skill_code' => $code, 'scope_type' => $scopeType, 'scope_id' => $scopeId ?? 0],
                [
                    'skill_id' => $employeeSkill?->skill_id,
                    'current_level' => $employeeSkill->proficiency_level_code ?? 'NONE',
                    'current_ordinal' => $currentOrdinal,
                    'required_level' => $requirement->required_level,
                    'required_ordinal' => $requiredOrdinal,
                    'gap' => $gap,
                    'priority' => $priority,
                    'development_options' => $this->developmentOptions($code, $gap),
                    'estimated_cost' => $this->ctx->money($this->trainingCostFor($code, $gap)),
                    'status' => 'OPEN',
                    'detected_at' => now(),
                    'plan_id' => $planId,
                ]
            );

            $rows[] = [
                'skill_code' => $code, 'current_level' => $row->current_level, 'required_level' => $row->required_level,
                'gap' => $gap, 'priority' => $priority, 'development_options' => $row->development_options,
                'estimated_cost' => (float) $row->estimated_cost, 'evidence' => [
                    'requirement_type' => $requirement->requirement_type,
                    'position_id' => $scopeId,
                ],
            ];
        }

        $this->ctx->event('SKILLS', 'SkillGapDetected', [
            'title' => 'Gap keterampilan terdeteksi', 'meta' => $summary,
        ], $employeeId, HrEmployee::class, $employeeId);

        return ['gaps' => $rows, 'summary' => $summary + ['requirements' => $requirements->count(), 'gaps' => count($rows)]];
    }

    /**
     * Heatmap keterampilan per departemen: coverage %, rata-rata level, gap.
     * Disajikan sebagai angka siap divisualisasikan (§23).
     *
     * @return array<string, mixed>
     */
    public function heatmap(?int $organizationId = null, ?int $departmentId = null, int $limit = 12): array
    {
        return $this->ctx->safe(function () use ($organizationId, $departmentId, $limit): array {
            $levels = (array) $this->ctx->config('skills.levels', []);
            $maxOrdinal = max(array_values($levels)) ?: 4;
            $rows = DB::table('hr_employee_skills as es')
                ->join('hr_skills as s', 's.id', '=', 'es.skill_id')
                ->join('hr_employees as e', 'e.id', '=', 'es.employee_id')
                ->where('e.is_active', true)
                ->when($organizationId, fn ($q) => $q->where('e.organization_id', $organizationId))
                ->when($departmentId, fn ($q) => $q->where('e.department_id', $departmentId))
                ->selectRaw('e.department_id as department_id, s.code as skill_code, s.name as skill_name,
                             es.proficiency_level_code as level, count(distinct es.employee_id) as holders,
                             e.department_id as dept')
                ->groupBy('e.department_id', 's.code', 's.name', 'es.proficiency_level_code')
                ->get();

            $headcountByDept = DB::table('hr_employees')->where('is_active', true)
                ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
                ->selectRaw('department_id, count(*) as total')->groupBy('department_id')->pluck('total', 'department_id');

            $matrix = [];
            foreach ($rows as $row) {
                $dept = (int) ($row->department_id ?? 0);
                $code = (string) $row->skill_code;
                $key = $dept.'|'.$code;
                $matrix[$key] ??= ['department_id' => $dept, 'skill_code' => $code, 'skill_name' => $row->skill_name, 'holders' => 0, 'ordinal_sum' => 0];
                $matrix[$key]['holders'] += (int) $row->holders;
                $matrix[$key]['ordinal_sum'] += (int) $row->holders * (int) ($levels[strtoupper((string) $row->level)] ?? 0);
            }

            $cells = [];
            foreach (array_slice($matrix, 0, $limit * 8) as $cell) {
                $headcount = (int) ($headcountByDept[$cell['department_id']] ?? 1);
                $avgOrdinal = $cell['holders'] > 0 ? $cell['ordinal_sum'] / $cell['holders'] : 0;
                $cells[] = [
                    'department_id' => $cell['department_id'],
                    'skill_code' => $cell['skill_code'],
                    'skill_name' => $cell['skill_name'],
                    'holders' => $cell['holders'],
                    'coverage_percent' => $this->ctx->percent($cell['holders'], max($headcount, 1)),
                    'average_level_percent' => $this->ctx->percent($avgOrdinal, $maxOrdinal),
                    'gap_percent' => $this->ctx->score(100 - $this->ctx->percent($cell['holders'], max($headcount, 1))),
                    'bar' => str_repeat('#', (int) round($this->ctx->percent($cell['holders'], max($headcount, 1)) / 5)),
                ];
            }

            usort($cells, fn (array $a, array $b): int => $b['coverage_percent'] <=> $a['coverage_percent']);

            return [
                'cells' => array_slice($cells, 0, $limit * 4),
                'departments' => count($headcountByDept),
                'max_level_ordinal' => $maxOrdinal,
                'note' => 'Coverage = pegawai pemegang keterampilan / pegawai di unit.',
            ];
        }, ['cells' => [], 'departments' => 0, 'note' => 'Data keterampilan belum tersedia']);
    }

    /**
     * Keterampilan kritis/strategis/emerging/scarce, menjadi input perencanaan,
     * rekrutmen, pelatihan, suksesi, dan marketplace (§24).
     *
     * @return array{codes:array<int, string>, rows:array<int, array<string, mixed>>}
     */
    public function criticalSkills(?int $organizationId = null): array
    {
        $criticalTypes = ['CRITICAL', 'STRATEGIC', 'EMERGING', 'SCARCE', 'BUSINESS_CRITICAL', 'REGULATORY'];
        $rows = $this->ctx->safe(function () use ($organizationId): array {
            $holders = DB::table('hr_employee_skills as es')
                ->join('hr_skills as s', 's.id', '=', 'es.skill_id')
                ->join('hr_employees as e', 'e.id', '=', 'es.employee_id')
                ->where('e.is_active', true)
                ->when($organizationId, fn ($q) => $q->where('e.organization_id', $organizationId))
                ->selectRaw('s.code as code, s.name as name, count(distinct es.employee_id) as holders')
                ->groupBy('s.code', 's.name')->pluck('holders', 'code')->all();

            $flagged = DB::table('hr_skill_demand')
                ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
                ->get(['skill_code', 'criticality', 'required_headcount', 'period', 'source'])
                ->groupBy('skill_code');

            $deliverables = [];
            foreach ($flagged as $code => $group) {
                $criticality = (string) ($group->sortByDesc(fn ($row): int => array_search($row->criticality, ['NORMAL', 'EMERGING', 'STRATEGIC', 'BUSINESS_CRITICAL', 'SCARCE', 'REGULATORY', 'CRITICAL'], true) ?: 0)
                    ->first()->criticality ?? 'NORMAL');
                if (! in_array(strtoupper($criticality), $criticalTypes, true)) {
                    continue;
                }
                $required = (int) $group->sum('required_headcount');
                $have = (int) ($holders[$code] ?? 0);
                $deliverables[] = [
                    'skill_code' => $code, 'criticality' => strtoupper($criticality),
                    'holders' => $have, 'required' => $required, 'gap' => max($required - $have, 0),
                    'scarcity' => $have === 0 ? 'NO_SUPPLY' : ($have <= 2 ? 'SCARCE' : 'AVAILABLE'),
                    'source' => (string) $group->first()->source,
                ];
            }

            return $deliverables;
        }, []);

        // Sertakan keterampilan yang ditandai kritis pada syarat posisi/job.
        $flaggedFromRequirements = $this->ctx->safe(fn (): array => HrPositionSkill::query()->where('is_critical', true)
            ->distinct()->pluck('skill_code')->all(), []);

        $codes = array_values(array_unique(array_merge(array_column($rows, 'skill_code'), $flaggedFromRequirements)));

        return ['codes' => $codes, 'rows' => $rows];
    }

    /**
     * Supply vs demand per keterampilan (§90).
     *
     * @return array<string, mixed>
     */
    public function supplyVsDemand(?int $organizationId = null, ?string $period = null): array
    {
        $period = $period ?? now()->format('Y');
        $levels = (array) $this->ctx->config('skills.levels', []);
        $supply = $this->ctx->safe(function () use ($organizationId, $levels): array {
            $rows = DB::table('hr_employee_skills as es')
                ->join('hr_skills as s', 's.id', '=', 'es.skill_id')
                ->join('hr_employees as e', 'e.id', '=', 'es.employee_id')
                ->where('e.is_active', true)
                ->when($organizationId, fn ($q) => $q->where('e.organization_id', $organizationId))
                ->selectRaw('s.code as code, es.proficiency_level_code as level, count(distinct es.employee_id) as holders')
                ->groupBy('s.code', 'es.proficiency_level_code')->get();

            $map = [];
            foreach ($rows as $row) {
                $ordinal = (int) ($levels[strtoupper((string) $row->level)] ?? 0);
                $map[$row->code] ??= ['total' => 0, 'advanced_plus' => 0];
                $map[$row->code]['total'] += (int) $row->holders;
                if ($ordinal >= 3) {
                    $map[$row->code]['advanced_plus'] += (int) $row->holders;
                }
            }

            return $map;
        }, []);

        $demand = $this->ctx->safe(fn (): array => DB::table('hr_skill_demand')
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->where('period', $period)
            ->selectRaw('skill_code, sum(required_headcount) as required, max(criticality) as criticality')
            ->groupBy('skill_code')->get()->mapWithKeys(fn ($row): array => [
                $row->skill_code => ['required' => (int) $row->required, 'criticality' => $row->criticality],
            ])->all(), []);

        $rows = [];
        foreach (array_unique(array_merge(array_keys($supply), array_keys($demand))) as $code) {
            $have = (int) ($supply[$code]['total'] ?? 0);
            $advanced = (int) ($supply[$code]['advanced_plus'] ?? 0);
            $required = (int) ($demand[$code]['required'] ?? 0);
            $gap = max($required - $have, 0);
            $rows[] = [
                'skill_code' => $code,
                'current_supply' => $have, 'advanced_supply' => $advanced,
                'future_demand' => $required, 'gap' => $gap,
                'criticality' => $demand[$code]['criticality'] ?? 'NORMAL',
                'development_capacity' => $have, // berapa pegawai yang bisa dikembangkan
                'hiring_alternative' => $gap,
                'scarcity_index' => $required > 0 ? $this->ctx->score(min(100, $this->ctx->percent($gap, $required))) : 0.0,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['gap'] <=> $a['gap']);

        return ['period' => $period, 'rows' => $rows, 'total_gap' => array_sum(array_column($rows, 'gap'))];
    }

    /**
     * Build / Buy / Borrow dengan perbandingan biaya (§91). Tidak memilih
     * otomatis — hanya menyajikan trade-off.
     *
     * @return array<string, mixed>
     */
    public function buildBuyBorrow(string $skillCode, int $gapCount, array $assumptions = []): array
    {
        $trainingCost = (float) ($assumptions['training_cost_per_employee'] ?? $this->ctx->config('cost.training_per_employee', 750000));
        $recruitmentCost = (float) ($assumptions['recruitment_cost_per_hire'] ?? $this->ctx->config('cost.recruitment_per_hire', 2500000));
        $externalMonthly = (float) ($assumptions['external_monthly_cost'] ?? $this->ctx->config('cost.external_monthly', 12000000));
        $months = (int) ($assumptions['external_months'] ?? 6);
        $rampMonths = (int) ($assumptions['productivity_ramp_months'] ?? 3);

        return [
            'skill_code' => $skillCode,
            'gap' => $gapCount,
            'options' => [
                'BUILD' => [
                    'label' => 'Latih pegawai yang ada',
                    'cost' => $this->ctx->money($trainingCost * $gapCount),
                    'duration_months' => 3,
                    'pros' => ['Moral & retensi naik', 'Biaya terendah', 'Pengetahuan kontekstual'],
                    'cons' => ['Butuh waktu', 'Bergantung kualitas pelatihan'],
                ],
                'BUY' => [
                    'label' => 'Rekrut pegawai baru',
                    'cost' => $this->ctx->money($recruitmentCost * $gapCount * (1 + $rampMonths / 12)),
                    'duration_months' => 1 + $rampMonths,
                    'pros' => ['Keahlian siap pakai', 'Membawa pengalaman baru'],
                    'cons' => ['Biaya tertinggi', 'Risiko budaya', 'Waktu onboarding'],
                ],
                'BORROW' => [
                    'label' => 'Kontraktor/partner/konsultan',
                    'cost' => $this->ctx->money($externalMonthly * $months),
                    'duration_months' => $months,
                    'pros' => ['Cepat', 'Fleksibel untuk beban puncak'],
                    'cons' => ['Pengetahuan tidak menetap', 'Ketergantungan vendor'],
                ],
            ],
            'note' => 'Pilihan tetap milik manusia; angka ini bahan pertimbangan.',
        ];
    }

    /**
     * Permintaan pelatihan dari gap keterampilan (§88, §98, §99).
     *
     * @return array<string, mixed>
     */
    public function learningDemand(array $filters = []): array
    {
        $gaps = $this->ctx->safe(fn (): array => DB::table('hr_skill_gaps')
            ->when(! empty($filters['organization_id']), fn ($q) => $q->whereIn('employee_id', HrEmployee::query()
                ->where('organization_id', $filters['organization_id'])->select('id')))
            ->where('status', 'OPEN')
            ->selectRaw('skill_code, count(*) as employees, sum(gap) as levels, max(priority) as top_priority, sum(estimated_cost) as cost')
            ->groupBy('skill_code')->orderByDesc('employees')->get()
            ->map(fn ($row): array => [
                'skill_code' => $row->skill_code, 'employees' => (int) $row->employees,
                'levels' => (int) $row->levels, 'priority' => $row->top_priority,
                'estimated_cost' => $this->ctx->money((float) $row->cost),
                'recommended_portfolio' => $this->developmentOptions((string) $row->skill_code, (int) $row->levels),
            ])->all(), []);

        return [
            'rows' => $gaps,
            'total_employees' => array_sum(array_column($gaps, 'employees')),
            'total_cost' => $this->ctx->money(array_sum(array_column($gaps, 'estimated_cost'))),
            'note' => 'Program pelatihan disusun dari gap nyata, bukan permintaan ad hoc.',
        ];
    }

    /**
     * Prakiraan permintaan keterampilan (§127 hr_skill_forecasts).
     *
     * @return array<int, HrSkillForecast>
     */
    public function forecastDemand(?int $organizationId = null, ?string $period = null, array $assumptions = []): array
    {
        $period = $period ?? Carbon::now()->addYear()->format('Y');
        $demandRows = $this->supplyVsDemand($organizationId, (string) Carbon::now()->format('Y'))['rows'];
        $growth = (float) ($assumptions['demand_growth_percent'] ?? 10.0);
        $rows = [];

        foreach ($demandRows as $row) {
            $futureDemand = (float) $row['future_demand'] > 0
                ? (float) $row['future_demand'] * (1 + $growth / 100)
                : (float) $row['current_supply'] * (1 + $growth / 100);
            $gap = max($futureDemand - (float) $row['current_supply'], 0);
            $rows[] = HrSkillForecast::query()->updateOrCreate(
                ['skill_code' => $row['skill_code'], 'organization_id' => $organizationId, 'period' => $period],
                [
                    'current_supply' => $row['current_supply'],
                    'future_demand' => round($futureDemand, 2),
                    'gap' => round($gap, 2),
                    'development_capacity' => $row['development_capacity'],
                    'hiring_alternative' => $row['hiring_alternative'],
                    'scarcity_index' => $row['scarcity_index'],
                    'method' => 'SUPPLY_DEMAND_WITH_GROWTH',
                    'assumptions' => $assumptions + ['growth_percent' => $growth],
                    'generated_at' => now(),
                ]
            );
        }

        return $rows;
    }

    /**
     * Pemeriksaan tata kelola keterampilan (§15: konsistensi lintas HR).
     *
     * @return array<string, mixed>
     */
    public function governanceReview(): array
    {
        $issues = $this->ctx->safe(function (): array {
            $duplicates = DB::table('hr_skills')->selectRaw('lower(name) as name, count(*) as total')
                ->groupBy('name')->havingRaw('count(*) > 1')->get();
            $missingCode = DB::table('hr_skills')->where(fn ($q) => $q->whereNull('code')->orWhere('code', ''))->count();
            $orphan = DB::table('hr_skills')->whereNull('category')->orWhere('category', '')->count();

            $rows = [];
            foreach ($duplicates as $duplicate) {
                $rows[] = HrSkillGovernance::query()->create([
                    'skill_code' => (string) $duplicate->name, 'policy' => 'DUPLICATE',
                    'status' => 'REVIEW_REQUIRED', 'issue' => 'Nama keterampilan duplikat ('.$duplicate->total.' entri)',
                    'last_reviewed_at' => now(),
                ]);
            }
            if ($missingCode > 0) {
                $rows[] = HrSkillGovernance::query()->create([
                    'policy' => 'NAMING', 'status' => 'REVIEW_REQUIRED',
                    'issue' => $missingCode.' keterampilan tanpa kode', 'last_reviewed_at' => now(),
                ]);
            }
            if ($orphan > 0) {
                $rows[] = HrSkillGovernance::query()->create([
                    'policy' => 'TAXONOMY', 'status' => 'REVIEW_REQUIRED',
                    'issue' => $orphan.' keterampilan tanpa kategori/domain', 'last_reviewed_at' => now(),
                ]);
            }

            return $rows;
        }, []);

        return ['issues' => $issues, 'count' => count($issues)];
    }

    /* ------------------------------------------------------------------ */

    private function priorityFor(?string $requirementType, int $gap, int $requiredOrdinal): string
    {
        $type = strtoupper((string) $requirementType);

        return match (true) {
            $type === 'CRITICAL' && $gap > 0 => 'CRITICAL',
            $gap >= 2 => 'HIGH',
            $type === 'REQUIRED' || $requiredOrdinal >= 3 => 'HIGH',
            $gap === 1 => 'MEDIUM',
            default => 'LOW',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function developmentOptions(string $skillCode, int $gap): array
    {
        $options = [[
            'type' => 'TRAINING', 'title' => 'Pelatihan '.$skillCode.' (level dasar–menengah)',
            'provider' => 'internal', 'duration_months' => 1, 'cost' => $this->ctx->money($this->trainingCostFor($skillCode, $gap)),
        ]];
        if ($gap >= 2) {
            $options[] = [
                'type' => 'MENTORING', 'title' => 'Pendampingan mentor untuk '.$skillCode,
                'provider' => 'internal', 'duration_months' => 3, 'cost' => 0.0,
            ];
            $options[] = [
                'type' => 'PROJECT', 'title' => 'Penugasan proyek nyata memakai '.$skillCode,
                'provider' => 'internal', 'duration_months' => 3, 'cost' => 0.0,
            ];
        }
        if ($gap >= 3) {
            $options[] = [
                'type' => 'CERTIFICATION', 'title' => 'Sertifikasi resmi '.$skillCode,
                'provider' => 'external', 'duration_months' => 4,
                'cost' => $this->ctx->money($this->trainingCostFor($skillCode, $gap) * 2.5),
            ];
        }

        return $options;
    }

    private function trainingCostFor(string $skillCode, int $gap): float
    {
        $fromCatalogue = $this->ctx->safe(fn (): float => (float) DB::table('hr_training_catalog')
            ->where('title', 'like', '%'.$skillCode.'%')->avg('cost_amount'), 0.0);

        return $fromCatalogue > 0
            ? $fromCatalogue
            : (float) $this->ctx->config('cost.training_per_employee', 750000) * max($gap, 1);
    }
}
