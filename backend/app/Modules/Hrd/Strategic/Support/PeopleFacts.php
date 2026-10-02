<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Support;

use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Support\Facades\DB;

/**
 * Fakta pegawai lintas modul dalam satu tempat: kinerja, potensi, kesiapan,
 * kompetensi, sertifikasi, pembelajaran, mentoring, tujuan, pay, engagement.
 *
 * Semua pembacaan tahan gagal (modul/tabel bisa belum ada) dan tidak pernah
 * menyalin data: yang dikembalikan adalah nilai saat ini dari sumber aslinya.
 */
class PeopleFacts
{
    public function __construct(private readonly Phase3Context $ctx) {}

    public function performanceScore(int $employeeId): ?float
    {
        return $this->ctx->safe(function () use ($employeeId): ?float {
            if (! $this->ctx->hasTable('hr_performance_reviews')) {
                return null;
            }
            $row = (array) (DB::table('hr_performance_reviews')->where('employee_id', $employeeId)->orderByDesc('id')->first() ?? []);
            foreach (['final_score', 'overall_score', 'rating', 'score'] as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    return round((float) $row[$key], 2);
                }
            }

            return null;
        }, null);
    }

    public function potentialScore(int $employeeId): ?float
    {
        return $this->ctx->safe(function () use ($employeeId): ?float {
            if ($this->ctx->hasTable('hr_potential_assessments')) {
                $value = DB::table('hr_potential_assessments')->where('employee_id', $employeeId)
                    ->orderByDesc('id')->value('potential_score');
                if ($value !== null) {
                    return round((float) $value, 2);
                }
            }
            if ($this->ctx->hasTable('hr_talent_review_results')) {
                $value = DB::table('hr_talent_review_results')->where('employee_id', $employeeId)
                    ->orderByDesc('id')->value('potential_rating');
                if ($value !== null) {
                    return round((float) $value, 2);
                }
            }

            return null;
        }, null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function talentReview(int $employeeId): ?array
    {
        return $this->ctx->safe(function () use ($employeeId): ?array {
            if (! $this->ctx->hasTable('hr_talent_review_results')) {
                return null;
            }
            $row = DB::table('hr_talent_review_results')->where('employee_id', $employeeId)->orderByDesc('id')->first();

            return $row ? (array) $row : null;
        }, null);
    }

    public function tenureMonths(int $employeeId): float
    {
        return (float) $this->ctx->safe(function () use ($employeeId): float {
            $hireDate = HrEmployee::query()->whereKey($employeeId)->value('hire_date');
            if (! $hireDate) {
                return 0.0;
            }

            return round($this->ctx->monthsBetween((string) $hireDate, now()), 1);
        }, 0.0);
    }

    public function monthlyPay(int $employeeId): ?float
    {
        return $this->ctx->safe(function () use ($employeeId): ?float {
            if (! $this->ctx->hasTable('hr_payroll_items')) {
                return null;
            }
            $value = DB::table('hr_payroll_items')->where('employee_id', $employeeId)->orderByDesc('id')->value('net_amount');

            return $value === null ? null : round((float) $value, 2);
        }, null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function competencyScores(int $employeeId): array
    {
        return $this->ctx->safe(function () use ($employeeId): array {
            if (! $this->ctx->hasTable('hr_performance_competency_assessments')) {
                return [];
            }

            return DB::table('hr_performance_competency_assessments')->where('employee_id', $employeeId)
                ->orderByDesc('id')->limit(20)->get()
                ->map(fn ($row): array => [
                    'competency_id' => (int) ($row->competency_id ?? 0),
                    'level' => isset($row->actual_level) ? (float) $row->actual_level : (isset($row->assessed_level) ? (float) $row->assessed_level : null),
                    'target' => isset($row->target_level) ? (float) $row->target_level : null,
                ])->all();
        }, []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function certifications(int $employeeId): array
    {
        return $this->ctx->safe(function () use ($employeeId): array {
            if (! $this->ctx->hasTable('hr_employee_certifications')) {
                return [];
            }

            return DB::table('hr_employee_certifications')->where('employee_id', $employeeId)->orderByDesc('id')->limit(20)->get()
                ->map(fn ($row): array => [
                    'name' => (string) ($row->name ?? ''),
                    'issuer' => $row->issuer ?? null,
                    'expiry_date' => $row->expiry_date ?? null,
                    'status' => $row->status ?? null,
                ])->all();
        }, []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function trainingCompletions(int $employeeId): array
    {
        return $this->ctx->safe(function () use ($employeeId): array {
            if (! $this->ctx->hasTable('hr_training_completions')) {
                return [];
            }

            return DB::table('hr_training_completions')->where('employee_id', $employeeId)->orderByDesc('id')->limit(20)->get()
                ->map(fn ($row): array => [
                    'training_id' => (int) ($row->training_id ?? 0),
                    'score' => isset($row->score) ? (float) $row->score : null,
                    'completed_at' => $row->completed_at ?? null,
                ])->all();
        }, []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function goals(int $employeeId): array
    {
        return $this->ctx->safe(function () use ($employeeId): array {
            foreach (['hr_performance_goals', 'hr_goals', 'hr_performance_objectives'] as $table) {
                if (! $this->ctx->hasTable($table)) {
                    continue;
                }

                return DB::table($table)->where('employee_id', $employeeId)->orderByDesc('id')->limit(10)->get()
                    ->map(fn ($row): array => [
                        'title' => (string) ($row->title ?? $row->name ?? ''),
                        'progress' => isset($row->progress_percent) ? (float) $row->progress_percent : (isset($row->progress) ? (float) $row->progress : null),
                        'status' => $row->status ?? null,
                    ])->all();
            }

            return [];
        }, []);
    }

    /**
     * @return array<int, array<string, mixed>>
        */
    public function projectAssignments(int $employeeId): array
    {
        return $this->ctx->safe(function () use ($employeeId): array {
            if (! $this->ctx->hasTable('hr_project_assignments')) {
                return [];
            }

            return DB::table('hr_project_assignments')->where('employee_id', $employeeId)->orderByDesc('id')->limit(20)->get()
                ->map(fn ($row): array => [
                    'project' => (string) ($row->project_name ?? $row->project_code ?? ''),
                    'role' => $row->role_title ?? null,
                    'allocation' => isset($row->allocation_percent) ? (float) $row->allocation_percent : null,
                    'status' => $row->status ?? null,
                ])->all();
        }, []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function mentoring(int $employeeId): array
    {
        return $this->ctx->safe(function () use ($employeeId): array {
            if (! $this->ctx->hasTable('hr_mentor_assignments')) {
                return [];
            }

            return DB::table('hr_mentor_assignments')->where('mentee_employee_id', $employeeId)->orderByDesc('id')->limit(10)->get()
                ->map(fn ($row): array => [
                    'mentor_employee_id' => (int) ($row->mentor_employee_id ?? 0),
                    'status' => $row->status ?? null,
                ])->all();
        }, []);
    }

    public function learningParticipation(int $employeeId): float
    {
        $completed = (int) $this->ctx->safe(fn (): int => DB::table('hr_training_completions')->where('employee_id', $employeeId)->count(), 0);
        $assigned = (int) $this->ctx->safe(fn (): int => DB::table('hr_training_assignments')->where('employee_id', $employeeId)->count(), 0);

        return $assigned > 0 ? $this->ctx->percent($completed, $assigned) : 0.0;
    }

    public function attendanceIrregularities(int $employeeId, ?string $period = null): int
    {
        $period = $period ?? now()->format('Y-m');

        return $this->ctx->safe(fn (): int => (int) DB::table('hr_attendance_records')
            ->where('employee_id', $employeeId)
            ->where('date', 'like', $period.'%')
            ->whereIn('status', ['ABSENT', 'LATE', 'UNPAID', 'NO_SHOW'])
            ->count(), 0);
    }

    public function monthsSincePromotion(int $employeeId): ?int
    {
        return $this->ctx->safe(function () use ($employeeId): ?int {
            foreach (['hr_assignments', 'hr_employee_assignments'] as $table) {
                if (! $this->ctx->hasTable($table)) {
                    continue;
                }
                $date = DB::table($table)->where('employee_id', $employeeId)->orderByDesc('id')->value('start_date');
                if ($date) {
                    return $this->ctx->monthsBetween((string) $date, now());
                }
            }

            return null;
        }, null);
    }

    public function managerChain(int $employeeId, int $levels = 4): array
    {
        return $this->ctx->safe(function () use ($employeeId, $levels): array {
            $chain = [];
            $current = HrEmployee::query()->whereKey($employeeId)->value('manager_employee_id');
            $guard = 0;
            while ($current && $guard < $levels) {
                $manager = HrEmployee::query()->find($current, ['id', 'employee_number', 'position_id', 'manager_employee_id']);
                if (! $manager) {
                    break;
                }
                $chain[] = ['employee_id' => $manager->getKey(), 'employee_number' => $manager->employee_number, 'position_id' => $manager->position_id];
                $current = $manager->manager_employee_id;
                $guard++;
            }

            return $chain;
        }, []);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(int $employeeId): array
    {
        return [
            'employee_id' => $employeeId,
            'performance_score' => $this->performanceScore($employeeId),
            'potential_score' => $this->potentialScore($employeeId),
            'tenure_months' => $this->tenureMonths($employeeId),
            'monthly_pay' => $this->monthlyPay($employeeId),
            'competencies' => $this->competencyScores($employeeId),
            'certifications' => $this->certifications($employeeId),
            'trainings' => $this->trainingCompletions($employeeId),
            'goals' => $this->goals($employeeId),
            'projects' => $this->projectAssignments($employeeId),
            'mentoring' => $this->mentoring($employeeId),
            'learning_participation' => $this->learningParticipation($employeeId),
            'months_since_promotion' => $this->monthsSincePromotion($employeeId),
            'manager_chain' => $this->managerChain($employeeId),
        ];
    }
}
