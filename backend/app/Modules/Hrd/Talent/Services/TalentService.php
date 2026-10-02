<?php

namespace App\Modules\Hrd\Talent\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Domain\Contracts\TalentProviderInterface;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Performance\Services\PerformanceService;
use App\Modules\Hrd\Talent\Models\HrPotentialAssessment;
use App\Modules\Hrd\Talent\Models\HrRecognition;
use App\Modules\Hrd\Talent\Models\HrRecognitionAward;
use App\Modules\Hrd\Talent\Models\HrTalentPool;
use App\Modules\Hrd\Talent\Models\HrTalentPoolMember;
use App\Modules\Hrd\Talent\Models\HrTalentReview;
use App\Modules\Hrd\Talent\Models\HrTalentReviewResult;
use Illuminate\Support\Facades\DB;

/**
 * Talenta: tinjauan, potensi, kumpulan talenta, dan pengakuan.
 *
 * Hasil tinjauan tidak pernah berupa kotak yang diketik begitu saja: kinerja
 * datang dari siklus kinerja, potensi dari penilaian potensi, dan 9-box
 * dihitung dari keduanya — sehingga setiap keputusan talenta dapat dipertanggung
 * jawabkan datanya.
 */
class TalentService implements TalentProviderInterface
{
    public function __construct(
        private readonly PerformanceService $performance,
        private readonly HrdPhase2Events $events,
    ) {}

    /**
     * Matriks kinerja x potensi. Perhitungannya milik domain kinerja, karena
     * sumbernya adalah siklus kinerja; talenta hanya membaca hasilnya sehingga
     * kedua layar tidak pernah menampilkan kotak yang berbeda.
     *
     * @return array<string, mixed>
     */
    public function nineBox(?int $organizationId = null, ?string $cycleCode = null): array
    {
        return $this->performance->nineBox($organizationId, $cycleCode);
    }

    /** @param array<string, mixed> $attributes */
    public function createReview(array $attributes, User $actor): HrTalentReview
    {
        return HrTalentReview::query()->create([
            'code' => strtoupper((string) ($attributes['code'] ?? 'TR-'.now()->format('YmdHis'))),
            'cycle_id' => $attributes['cycle_id'] ?? null,
            'name' => $attributes['name'] ?? 'Tinjauan Talenta',
            'review_type' => strtoupper((string) ($attributes['review_type'] ?? 'PERFORMANCE_POTENTIAL')),
            'organization_id' => $attributes['organization_id'] ?? null,
            'facilitator_employee_id' => $attributes['facilitator_employee_id'] ?? null,
            'scheduled_at' => $attributes['scheduled_at'] ?? now(),
            'status' => 'IN_PROGRESS',
            'participants' => $attributes['participants'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function recordResult(HrTalentReview $review, HrEmployee $employee, array $attributes, User $actor): HrTalentReviewResult
    {
        $performance = (float) ($attributes['performance_rating'] ?? 0);
        $potential = (float) ($attributes['potential_rating'] ?? 0);

        if ($performance === 0.0) {
            $latest = $this->performance->latestRating((int) $employee->getKey());
            $performance = (float) ($latest['rating'] ?? 0);
        }

        if ($potential === 0.0) {
            $assessment = HrPotentialAssessment::query()
                ->where('employee_id', $employee->getKey())
                ->orderByDesc('id')
                ->first();

            $potential = (float) ($assessment?->potential_score ?? 0);
        }

        return DB::transaction(function () use ($review, $employee, $attributes, $actor, $performance, $potential) {
            $nineBox = $this->performance->nineBox(null, $attributes['cycle_code'] ?? null);

            $result = HrTalentReviewResult::query()->updateOrCreate(
                ['review_id' => $review->getKey(), 'employee_id' => $employee->getKey()],
                [
                    'performance_rating' => $performance,
                    'potential_rating' => $potential,
                    'nine_box_code' => $this->nineBoxCode($performance, $potential, $nineBox['cutoffs']),
                    'readiness' => strtoupper((string) ($attributes['readiness'] ?? 'READY_SOON')),
                    'career_aspiration' => $attributes['career_aspiration'] ?? null,
                    'risk_of_loss' => strtoupper((string) ($attributes['risk_of_loss'] ?? 'LOW')),
                    'impact_of_loss' => strtoupper((string) ($attributes['impact_of_loss'] ?? 'LOW')),
                    'development_actions' => $attributes['development_actions'] ?? null,
                    'source_cycle_code' => $attributes['cycle_code'] ?? null,
                    'source_review_id' => $attributes['source_review_id'] ?? null,
                    'decided_by' => $actor->getKey(),
                    'decided_at' => now(),
                ],
            );

            $this->events->emit('TALENT', 'talent.result_recorded', [
                'actor' => $actor,
                'employee_id' => $employee->getKey(),
                'subject_type' => HrTalentReviewResult::class,
                'subject_id' => $result->getKey(),
                'title' => 'Hasil tinjauan talenta: '.$employee->displayName(),
                'payload' => ['performance' => $performance, 'potential' => $potential, 'nine_box' => $result->nine_box_code],
            ]);

            return $result->refresh();
        });
    }

    public function completeReview(HrTalentReview $review, User $actor, ?string $notes = null): HrTalentReview
    {
        $review->forceFill([
            'status' => 'COMPLETED',
            'notes' => $notes ?? $review->notes,
        ])->save();

        return $review->refresh();
    }

    public function review(int $employeeId, ?string $cycleCode = null): ?array
    {
        $result = HrTalentReviewResult::query()
            ->with('review')
            ->where('employee_id', $employeeId)
            ->when($cycleCode !== null, fn ($query) => $query->where('source_cycle_code', $cycleCode))
            ->orderByDesc('id')
            ->first();

        if ($result === null) {
            $latest = $this->performance->latestRating($employeeId, $cycleCode);

            return $latest === null ? null : ['source' => 'performance_cycle'] + $latest;
        }

        return [
            'source' => 'talent_review',
            'review' => $result->review?->code,
            'performance' => (float) $result->performance_rating,
            'potential' => (float) $result->potential_rating,
            'nine_box' => $result->nine_box_code,
            'readiness' => $result->readiness,
            'risk_of_loss' => $result->risk_of_loss,
            'aspiration' => $result->career_aspiration,
        ];
    }

    /** @param array<string, mixed> $attributes */
    public function createPool(array $attributes, User $actor): HrTalentPool
    {
        $poolType = strtoupper((string) ($attributes['pool_type'] ?? 'HIGH_POTENTIAL'));
        $allowed = (array) config('hrd_phase2.talent.pools', []);

        if ($allowed !== [] && ! in_array($poolType, $allowed, true)) {
            throw HrdValidationException::forField('pool_type', "Jenis kumpulan talenta \"{$poolType}\" tidak dikenal.", 'talent.pool_type_invalid');
        }

        return HrTalentPool::query()->updateOrCreate(
            ['code' => strtoupper((string) ($attributes['code'] ?? $poolType.'-'.now()->format('Y')))],
            [
                'name' => $attributes['name'] ?? $poolType,
                'pool_type' => $poolType,
                'description' => $attributes['description'] ?? null,
                'organization_id' => $attributes['organization_id'] ?? null,
                'owner_employee_id' => $attributes['owner_employee_id'] ?? null,
            ],
        );
    }

    /** @param array<string, mixed> $attributes */
    public function addToPool(HrTalentPool $pool, HrEmployee $employee, array $attributes, User $actor): HrTalentPoolMember
    {
        return HrTalentPoolMember::query()->updateOrCreate(
            ['pool_id' => $pool->getKey(), 'employee_id' => $employee->getKey()],
            [
                'added_by' => $actor->getKey(),
                'added_at' => now(),
                'readiness' => strtoupper((string) ($attributes['readiness'] ?? 'READY_SOON')),
                'rank' => $attributes['rank'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'status' => 'ACTIVE',
                'source_review_result_id' => $attributes['source_review_result_id'] ?? null,
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    public function poolMembers(string $poolCode): array
    {
        $pool = HrTalentPool::query()->where('code', strtoupper($poolCode))->first();

        if ($pool === null) {
            return [];
        }

        return HrTalentPoolMember::query()
            ->with('employee')
            ->where('pool_id', $pool->getKey())
            ->where('status', 'ACTIVE')
            ->orderBy('rank')
            ->get()
            ->map(fn (HrTalentPoolMember $member) => [
                'employee_id' => $member->employee_id,
                'name' => $member->employee?->displayName(),
                'readiness' => $member->readiness,
                'rank' => $member->rank,
                'added_at' => $member->added_at?->toDateTimeString(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function highPotentials(?int $organizationId = null): array
    {
        $poolIds = HrTalentPool::query()
            ->whereIn('pool_type', ['HIGH_POTENTIAL', 'LEADERSHIP', 'SUCCESSION_CANDIDATE'])
            ->when($organizationId !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId)))
            ->pluck('id');

        return HrTalentPoolMember::query()
            ->with(['employee', 'pool'])
            ->whereIn('pool_id', $poolIds)
            ->where('status', 'ACTIVE')
            ->get()
            ->map(fn (HrTalentPoolMember $member) => [
                'employee_id' => $member->employee_id,
                'name' => $member->employee?->displayName(),
                'pool' => $member->pool?->name,
                'readiness' => $member->readiness,
            ])
            ->all();
    }

    /**
     * Fondasi suksesi (§119): kandidat yang siap untuk sebuah posisi, dihitung
     * dari anggota kumpulan talenta + kesiapan + jalur karier.
     *
     * @return array<string, mixed>
     */
    public function successionReady(?int $positionId = null): array
    {
        $candidates = HrTalentPoolMember::query()
            ->with('employee')
            ->where('status', 'ACTIVE')
            ->whereIn('readiness', ['READY_NOW', 'READY_SOON'])
            ->get()
            ->map(fn (HrTalentPoolMember $member) => [
                'employee_id' => $member->employee_id,
                'name' => $member->employee?->displayName(),
                'readiness' => $member->readiness,
                'current_position_id' => $member->employee?->position_id,
            ])
            ->all();

        return [
            'position_id' => $positionId,
            'candidates' => $candidates,
            'note' => 'Fondasi Phase 3: peringkat kecocokan posisi dan skenario suksesi akan ditambahkan tanpa mengubah struktur ini.',
        ];
    }

    /** @param array<string, mixed> $attributes */
    public function recordPotential(HrEmployee $employee, array $attributes, User $actor): HrPotentialAssessment
    {
        return HrPotentialAssessment::query()->create([
            'employee_id' => $employee->getKey(),
            'cycle_id' => $attributes['cycle_id'] ?? null,
            'assessed_by_employee_id' => $attributes['assessed_by_employee_id'] ?? $employee->manager_employee_id,
            'assessment_method' => strtoupper((string) ($attributes['assessment_method'] ?? 'MANAGER_RATING')),
            'potential_score' => (float) ($attributes['potential_score'] ?? 0),
            'learning_agility' => $attributes['learning_agility'] ?? null,
            'aspiration' => $attributes['aspiration'] ?? null,
            'mobility' => $attributes['mobility'] ?? null,
            'summary' => $attributes['summary'] ?? null,
            'assessed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function createAward(array $attributes, User $actor): HrRecognitionAward
    {
        return HrRecognitionAward::query()->updateOrCreate(
            ['code' => strtoupper((string) $attributes['code'])],
            [
                'name' => $attributes['name'] ?? $attributes['code'],
                'category' => strtoupper((string) ($attributes['category'] ?? 'AWARD')),
                'description' => $attributes['description'] ?? null,
                'points' => (int) ($attributes['points'] ?? 0),
                'organization_id' => $attributes['organization_id'] ?? null,
            ],
        );
    }

    /** @param array<string, mixed> $attributes */
    public function recognize(HrEmployee $employee, array $attributes, User $actor): HrRecognition
    {
        $award = isset($attributes['award_id'])
            ? HrRecognitionAward::query()->find($attributes['award_id'])
            : (isset($attributes['award_code']) ? HrRecognitionAward::query()->where('code', strtoupper((string) $attributes['award_code']))->first() : null);

        $recognition = HrRecognition::query()->create([
            'code' => sprintf('REC-%s-%05d', now()->format('Y'), HrRecognition::query()->whereYear('created_at', now()->year)->count() + 1),
            'employee_id' => $employee->getKey(),
            'award_id' => $award?->getKey(),
            'recognition_type' => strtoupper((string) ($attributes['recognition_type'] ?? ($award?->category ?? 'THANK_YOU'))),
            'given_by_user_id' => $actor->getKey(),
            'given_by_employee_id' => $attributes['given_by_employee_id'] ?? null,
            'source' => strtoupper((string) ($attributes['source'] ?? 'MANAGER')),
            'title' => $attributes['title'] ?? ($award?->name ?? 'Apresiasi'),
            'message' => $attributes['message'] ?? null,
            'is_public' => (bool) ($attributes['is_public'] ?? true),
            'points' => (int) ($attributes['points'] ?? $award?->points ?? 0),
            'recognized_at' => now(),
            'period_code' => $attributes['period_code'] ?? now()->format('Y-m'),
        ]);

        $this->events->emit('TALENT', 'recognition.given', [
            'actor' => $actor,
            'employee_id' => $employee->getKey(),
            'subject_type' => HrRecognition::class,
            'subject_id' => $recognition->getKey(),
            'title' => 'Apresiasi: '.$recognition->title,
            'payload' => ['points' => (int) $recognition->points, 'source' => $recognition->source],
        ]);

        return $recognition;
    }

    /** @return array<string, mixed> */
    public function dashboard(?int $organizationId = null): array
    {
        $poolMembers = HrTalentPoolMember::query()
            ->when($organizationId !== null, fn ($query) => $query->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->where('status', 'ACTIVE')
            ->get();

        $results = HrTalentReviewResult::query()
            ->when($organizationId !== null, fn ($query) => $query->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->get();

        return [
            'talent_pools' => HrTalentPool::query()->where('is_active', true)->count(),
            'pool_members' => $poolMembers->count(),
            'ready_now' => $poolMembers->where('readiness', 'READY_NOW')->count(),
            'readiness_breakdown' => $poolMembers->groupBy('readiness')->map->count()->all(),
            'nine_box_breakdown' => $results->groupBy('nine_box_code')->map->count()->all(),
            'high_risk' => $results->where('risk_of_loss', 'HIGH')->count(),
            'critical_impact' => $results->where('impact_of_loss', 'HIGH')->count(),
            'recognitions_this_year' => HrRecognition::query()
                ->when($organizationId !== null, fn ($query) => $query->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
                ->whereYear('recognized_at', now()->year)
                ->count(),
        ];
    }

    /** @param array<string, mixed> $cutoffs */
    private function nineBoxCode(float $performance, float $potential, array $cutoffs): string
    {
        $performanceLow = (float) ($cutoffs['performance']['low'] ?? 2.5);
        $performanceHigh = (float) ($cutoffs['performance']['high'] ?? 3.8);
        $potentialLow = (float) ($cutoffs['potential']['low'] ?? 2.5);
        $potentialHigh = (float) ($cutoffs['potential']['high'] ?? 3.8);

        $performanceBand = $performance >= $performanceHigh ? 'high' : ($performance >= $performanceLow ? 'medium' : 'low');
        $potentialBand = $potential >= $potentialHigh ? 'high' : ($potential >= $potentialLow ? 'medium' : 'low');

        return strtoupper($performanceBand.'_'.$potentialBand);
    }
}
