<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\BoardingProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\StudentProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Exceptions\LaundryDomainException;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPolicy;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPolicyVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kebijakan jatah laundry gratis (§5) dengan versi yang tidak pernah diubah.
 *
 * Rule 22: perubahan policy melahirkan versi baru; entitlement historis tetap
 * menunjuk versi lama. Resolusi policy untuk satu santri bersifat deterministik:
 * versi PUBLISHED yang efektif pada tanggal tertentu, scope cocok, lalu
 * spesifisitas (dormitory > level > kategori > school) + priority.
 */
class LaundryPolicyService
{
    /** Bobot spesifisitas scope — scope lebih spesifik menang. */
    private const SPECIFICITY = [
        'dormitory_id' => 64,
        'education_level_id' => 32,
        'student_category' => 16,
        'academic_year_id' => 8,
        'school_id' => 4,
    ];

    public function __construct(
        private readonly StudentProviderInterface $students,
        private readonly BoardingProviderInterface $boarding,
        private readonly LaundryAuditService $audit,
    ) {
    }

    public function createPolicy(array $data, ?User $actor = null): LaundryPolicy
    {
        $policy = LaundryPolicy::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $actor?->id,
        ]);

        $this->audit->record('POLICY_CREATED', $policy, $actor, [], $policy->only(['id', 'code', 'name']));

        return $policy;
    }

    /**
     * Buat versi baru sebuah policy (DRAFT). Versi lama tidak pernah diubah —
     * arsipkan lewat `archiveVersion()` bila sudah tidak berlaku.
     */
    public function createVersion(LaundryPolicy $policy, array $data, ?User $actor = null): LaundryPolicyVersion
    {
        $this->assertQuotaType((string) ($data['quota_type'] ?? 'WEIGHT'));
        $this->assertPeriodType((string) ($data['period_type'] ?? 'MONTHLY'));
        $this->assertCarryOverMode((string) ($data['carry_over_mode'] ?? 'NO_CARRY_OVER'));

        $nextVersion = (int) $policy->versions()->max('version') + 1;

        $version = LaundryPolicyVersion::create([
            'laundry_policy_id' => $policy->id,
            'version' => $nextVersion,
            'quota_type' => $data['quota_type'] ?? 'WEIGHT',
            'quota_amount' => (float) ($data['quota_amount'] ?? 0),
            'period_type' => $data['period_type'] ?? 'MONTHLY',
            'custom_period_start' => $data['custom_period_start'] ?? null,
            'custom_period_end' => $data['custom_period_end'] ?? null,
            'carry_over_mode' => $data['carry_over_mode'] ?? 'NO_CARRY_OVER',
            'carry_over_limit' => $data['carry_over_limit'] ?? null,
            'max_free_weight_per_order' => $data['max_free_weight_per_order'] ?? null,
            'school_id' => $data['school_id'] ?? null,
            'dormitory_id' => $data['dormitory_id'] ?? null,
            'education_level_id' => $data['education_level_id'] ?? null,
            'student_category' => $data['student_category'] ?? null,
            'academic_year_id' => $data['academic_year_id'] ?? null,
            'effective_from' => $data['effective_from'],
            'effective_until' => $data['effective_until'] ?? null,
            'priority' => (int) ($data['priority'] ?? 0),
            'status' => 'DRAFT',
            'created_by' => $actor?->id,
        ]);

        $this->audit->record('POLICY_VERSION_CREATED', $version, $actor, [], [
            'policy' => $policy->code, 'version' => $nextVersion,
            'quota' => $version->quota_amount.' '.$version->quota_type.'/'.$version->period_type,
        ]);

        return $version;
    }

    /** Publikasikan versi draft — mulai berlaku sesuai effective_from. */
    public function publishVersion(LaundryPolicyVersion $version, ?User $actor = null): LaundryPolicyVersion
    {
        if ($version->status === 'PUBLISHED') {
            return $version;
        }

        if ($version->status === 'ARCHIVED') {
            throw ValidationException::withMessages([
                'status' => 'Versi terarsip tidak dapat dipublikasikan ulang; buat versi baru.',
            ]);
        }

        $before = ['status' => $version->status];
        $version->status = 'PUBLISHED';
        $version->published_at = now();
        $version->published_by = $actor?->id;
        $version->save();

        $this->audit->record('POLICY_VERSION_PUBLISHED', $version, $actor, $before, ['status' => 'PUBLISHED']);

        return $version;
    }

    public function archiveVersion(LaundryPolicyVersion $version, ?User $actor = null, ?string $reason = null): LaundryPolicyVersion
    {
        $before = ['status' => $version->status];
        $version->status = 'ARCHIVED';
        $version->save();

        $this->audit->record('POLICY_VERSION_ARCHIVED', $version, $actor, $before, ['status' => 'ARCHIVED'], $reason);

        return $version;
    }

    /**
     * Versi policy yang berlaku untuk satu santri pada tanggal tertentu.
     * Null = tidak ada jatah gratis (semua laundry berbayar).
     */
    public function resolveForStudent(int $studentId, ?string $onDate = null): ?LaundryPolicyVersion
    {
        $date = $onDate ?: now()->toDateString();
        $context = $this->studentContext($studentId, $date);

        $candidates = LaundryPolicyVersion::query()
            ->where('status', 'PUBLISHED')
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->whereHas('policy', fn ($q) => $q->where('is_active', true))
            ->get();

        $best = null;
        $bestScore = null;

        foreach ($candidates as $version) {
            if (! $this->matchesScope($version, $context)) {
                continue;
            }

            $score = $this->specificityScore($version) * 100000
                + ((int) $version->priority) * 100
                + (int) $version->id;

            if ($bestScore === null || $score > $bestScore) {
                $best = $version;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** Konteks santri untuk pencocokan scope policy. */
    public function studentContext(int $studentId, ?string $date = null): array
    {
        $snapshot = $this->students->snapshot($studentId) ?? [];
        $placement = $this->boarding->placementFor($studentId, $date);

        return [
            'school_id' => isset($snapshot['school_id']) ? (int) $snapshot['school_id'] : null,
            'education_level_id' => isset($snapshot['education_level_id']) ? (int) $snapshot['education_level_id'] : null,
            'dormitory_id' => isset($placement['dormitory_id']) ? (int) $placement['dormitory_id'] : null,
            'student_category' => $placement !== null ? 'BOARDING' : 'DAY',
            'academic_year_id' => $this->activeAcademicYearId($date),
        ];
    }

    private function matchesScope(LaundryPolicyVersion $version, array $context): bool
    {
        foreach (array_keys(self::SPECIFICITY) as $field) {
            $required = $version->{$field};
            if ($required !== null && (string) $required !== (string) ($context[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function specificityScore(LaundryPolicyVersion $version): int
    {
        $score = 0;
        foreach (self::SPECIFICITY as $field => $weight) {
            if ($version->{$field} !== null) {
                $score += $weight;
            }
        }

        return $score;
    }

    private function activeAcademicYearId(?string $date): ?int
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('academic_years')) {
                return null;
            }

            $date = $date ?: now()->toDateString();

            $row = DB::table('academic_years')
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->orderByDesc('is_active')
                ->orderByDesc('id')
                ->first(['id']);

            return $row ? (int) $row->id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertQuotaType(string $type): void
    {
        if (! in_array($type, (array) config('laundry.quota_types', []), true)) {
            throw new LaundryDomainException("Quota type tidak dikenal: {$type}");
        }
    }

    private function assertPeriodType(string $type): void
    {
        if (! in_array($type, (array) config('laundry.period_types', []), true)) {
            throw new LaundryDomainException("Period type tidak dikenal: {$type}");
        }
    }

    private function assertCarryOverMode(string $mode): void
    {
        if (! in_array($mode, (array) config('laundry.carry_over_modes', []), true)) {
            throw new LaundryDomainException("Carry over mode tidak dikenal: {$mode}");
        }
    }
}
