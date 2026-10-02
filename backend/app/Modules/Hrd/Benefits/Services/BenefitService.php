<?php

namespace App\Modules\Hrd\Benefits\Services;

use App\Models\User;
use App\Modules\Hrd\Benefits\Models\HrBenefitDependent;
use App\Modules\Hrd\Benefits\Models\HrBenefitEligibility;
use App\Modules\Hrd\Benefits\Models\HrBenefitEnrollment;
use App\Modules\Hrd\Benefits\Models\HrBenefitPlan;
use App\Modules\Hrd\Benefits\Models\HrBenefitTransaction;
use App\Modules\Hrd\Benefits\Models\HrBenefitType;
use App\Modules\Hrd\Core\Domain\Contracts\BenefitsProviderInterface;
use App\Modules\Hrd\Core\Services\EligibilityEngine;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Payroll\Services\SalaryStructureService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Benefit: rencana, kelayakan, dan pendaftaran.
 *
 * Iuran pegawai tidak disimpan sebagai "potongan benefit" yang berdiri sendiri:
 * ia dituliskan sebagai komponen gaji berjenis DEDUCTION, sehingga payroll
 * memotongnya seperti potongan lain dan slip gaji tetap satu sumber kebenaran.
 */
class BenefitService implements BenefitsProviderInterface
{
    public function __construct(
        private readonly EligibilityEngine $eligibility,
        private readonly SalaryStructureService $structures,
        private readonly HrdPhase2Events $events,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createPlan(array $attributes, User $actor): HrBenefitPlan
    {
        $type = HrBenefitType::query()->firstOrCreate(
            ['code' => strtoupper((string) ($attributes['benefit_type_code'] ?? 'OTHER'))],
            ['name' => $attributes['benefit_type_name'] ?? 'Benefit Lainnya', 'category' => $attributes['category'] ?? 'OTHER'],
        );

        return HrBenefitPlan::query()->create([
            'code' => strtoupper((string) ($attributes['code'] ?? 'BEN-'.now()->format('YmdHis'))),
            'name' => $attributes['name'] ?? $type->name,
            'benefit_type_id' => $type->getKey(),
            'organization_id' => $attributes['organization_id'] ?? null,
            'coverage_tier' => $attributes['coverage_tier'] ?? null,
            'employee_contribution' => (float) ($attributes['employee_contribution'] ?? 0),
            'employer_contribution' => (float) ($attributes['employer_contribution'] ?? 0),
            'contribution_period' => strtoupper((string) ($attributes['contribution_period'] ?? 'MONTHLY')),
            'salary_component_code' => $attributes['salary_component_code'] ?? null,
            'waiting_days' => (int) ($attributes['waiting_days'] ?? config('hrd_phase2.benefits.default_waiting_days', 0)),
            'enrollment_window' => strtoupper((string) ($attributes['enrollment_window'] ?? 'ANNUAL')),
            'requires_dependents' => (bool) ($attributes['requires_dependents'] ?? false),
            'max_dependents' => $attributes['max_dependents'] ?? null,
            'eligibility_rule_id' => $attributes['eligibility_rule_id'] ?? null,
            'effective_from' => $attributes['effective_from'] ?? now()->startOfYear()->toDateString(),
            'effective_to' => $attributes['effective_to'] ?? null,
            'plan_document_path' => $attributes['plan_document_path'] ?? null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function eligiblePlans(int $employeeId): array
    {
        $employee = HrEmployee::query()->find($employeeId);

        if ($employee === null) {
            return [];
        }

        return HrBenefitPlan::query()
            ->with('benefitType')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $employee->organization_id))
            ->get()
            ->filter(fn (HrBenefitPlan $plan) => $this->check($employee, $plan)['eligible'])
            ->map(fn (HrBenefitPlan $plan) => [
                'plan_id' => $plan->getKey(),
                'code' => $plan->code,
                'name' => $plan->name,
                'category' => $plan->benefitType?->category,
                'employee_contribution' => (float) $plan->employee_contribution,
                'employer_contribution' => (float) $plan->employer_contribution,
                'enrollment_window' => $plan->enrollment_window,
                'requires_dependents' => (bool) $plan->requires_dependents,
            ])
            ->values()
            ->all();
    }

    public function activeEnrollments(int $employeeId): array
    {
        return HrBenefitEnrollment::query()
            ->with(['plan.benefitType', 'dependents'])
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['ENROLLED', 'APPROVED'])
            ->get()
            ->map(fn (HrBenefitEnrollment $enrollment) => [
                'enrollment_id' => $enrollment->getKey(),
                'code' => $enrollment->code,
                'plan' => $enrollment->plan?->name,
                'status' => $enrollment->status,
                'coverage_start' => $enrollment->coverage_start?->toDateString(),
                'coverage_end' => $enrollment->coverage_end?->toDateString(),
                'employee_contribution' => (float) $enrollment->employee_contribution,
                'employer_contribution' => (float) $enrollment->employer_contribution,
                'dependents' => $enrollment->dependents->count(),
            ])
            ->all();
    }

    public function monthlyDeduction(int $employeeId, string $periodCode): float
    {
        $month = Carbon::parse($periodCode.'-01');

        return (float) HrBenefitEnrollment::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['ENROLLED', 'APPROVED'])
            ->where('coverage_start', '<=', $month->copy()->endOfMonth()->toDateString())
            ->where(fn ($query) => $query->whereNull('coverage_end')->orWhere('coverage_end', '>=', $month->copy()->startOfMonth()->toDateString()))
            ->get()
            ->sum(fn (HrBenefitEnrollment $enrollment) => match (strtoupper((string) $enrollment->plan?->contribution_period)) {
                'ANNUAL' => (float) $enrollment->employee_contribution / 12,
                'ONE_TIME' => 0.0,
                default => (float) $enrollment->employee_contribution,
            });
    }

    /** @param array<string, mixed> $attributes */
    public function enroll(int $employeeId, int $planId, array $attributes, ?int $actorUserId = null): array
    {
        $employee = HrEmployee::query()->findOrFail($employeeId);
        $plan = HrBenefitPlan::query()->findOrFail($planId);
        $actor = $actorUserId !== null ? User::query()->findOrFail($actorUserId) : $employee->user;

        if ($actor === null) {
            throw HrdValidationException::forField('actor', 'Pelaku perubahan wajib diketahui.', 'benefit.actor_required');
        }

        $enrollment = $this->enrollEmployee($employee, $plan, $attributes, $actor);

        return ['enrollment_id' => $enrollment->getKey(), 'code' => $enrollment->code, 'status' => $enrollment->status];
    }

    /** @param array<string, mixed> $attributes */
    public function enrollEmployee(HrEmployee $employee, HrBenefitPlan $plan, array $attributes, User $actor): HrBenefitEnrollment
    {
        $check = $this->check($employee, $plan);

        if (! $check['eligible']) {
            throw HrdValidationException::forField('plan_id', 'Pegawai belum memenuhi syarat benefit ini: '.implode('; ', $check['reasons']), 'benefit.not_eligible');
        }

        $key = (string) ($attributes['idempotency_key'] ?? 'benefit-enroll:'.$employee->getKey().':'.$plan->getKey());

        $existing = HrBenefitEnrollment::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $dependents = $attributes['dependents'] ?? [];

        if ((bool) $plan->requires_dependents && $dependents === []) {
            throw HrdValidationException::forField('dependents', 'Rencana ini memerlukan data tanggungan.', 'benefit.dependents_required');
        }

        if ($plan->max_dependents !== null && count($dependents) > (int) $plan->max_dependents) {
            throw HrdValidationException::forField('dependents', 'Jumlah tanggungan melebihi batas rencana.', 'benefit.dependents_exceeded');
        }

        return DB::transaction(function () use ($employee, $plan, $attributes, $actor, $dependents, $key) {
            $coverageStart = Carbon::parse($attributes['coverage_start'] ?? now()->addDays((int) $plan->waiting_days)->toDateString());

            $enrollment = HrBenefitEnrollment::query()->create([
                'code' => sprintf('ENR-%s-%04d', now()->format('Y'), HrBenefitEnrollment::query()->whereYear('created_at', now()->year)->count() + 1),
                'employee_id' => $employee->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => 'ENROLLED',
                'coverage_start' => $coverageStart->toDateString(),
                'coverage_end' => $attributes['coverage_end'] ?? null,
                'employee_contribution' => (float) $plan->employee_contribution,
                'employer_contribution' => (float) $plan->employer_contribution,
                'dependent_count' => count($dependents),
                'policy_number' => $attributes['policy_number'] ?? null,
                'elected_at' => now(),
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'idempotency_key' => $key,
            ]);

            foreach ($dependents as $dependent) {
                HrBenefitDependent::query()->create([
                    'enrollment_id' => $enrollment->getKey(),
                    'name' => $dependent['name'] ?? 'Tanpa Nama',
                    'relationship' => strtoupper((string) ($dependent['relationship'] ?? 'OTHER')),
                    'national_id' => $dependent['national_id'] ?? null,
                    'birth_date' => $dependent['birth_date'] ?? null,
                    'gender' => $dependent['gender'] ?? null,
                    'is_covered' => (bool) ($dependent['is_covered'] ?? true),
                    'verification_status' => config('hrd_phase2.benefits.require_dependent_verification', true) ? 'PENDING' : 'VERIFIED',
                    'document_path' => $dependent['document_path'] ?? null,
                ]);
            }

            // Iuran pegawai menjadi komponen gaji, sehingga payroll memotongnya
            // bersama potongan lain dan slip gaji tetap konsisten.
            if ((float) $plan->employee_contribution > 0) {
                $this->structures->setComponent($employee, $plan->salary_component_code ?? ('BENEFIT_'.$plan->code), [
                    'amount' => (float) $plan->employee_contribution,
                    'effective_from' => $coverageStart->copy()->startOfMonth()->toDateString(),
                    'source' => 'BENEFIT',
                ], $actor);
            }

            HrBenefitTransaction::query()->create([
                'enrollment_id' => $enrollment->getKey(),
                'employee_id' => $employee->getKey(),
                'plan_id' => $plan->getKey(),
                'transaction_type' => 'ENROLL',
                'effective_date' => $coverageStart->toDateString(),
                'amount' => (float) $plan->employee_contribution,
                'actor_user_id' => $actor->getKey(),
                'payload' => ['dependents' => count($dependents)],
            ]);

            $this->events->emit('BENEFITS', 'benefit.enrolled', [
                'actor' => $actor,
                'employee_id' => $employee->getKey(),
                'subject_type' => HrBenefitEnrollment::class,
                'subject_id' => $enrollment->getKey(),
                'title' => 'Terdaftar pada benefit '.$plan->name,
                'payload' => ['coverage_start' => $coverageStart->toDateString(), 'contribution' => (float) $plan->employee_contribution],
            ]);

            return $enrollment->refresh();
        });
    }

    public function decline(HrEmployee $employee, HrBenefitPlan $plan, User $actor, string $reason): HrBenefitEnrollment
    {
        $enrollment = HrBenefitEnrollment::query()->updateOrCreate(
            ['employee_id' => $employee->getKey(), 'plan_id' => $plan->getKey()],
            [
                'code' => sprintf('ENR-%s-%04d', now()->format('Y'), HrBenefitEnrollment::query()->whereYear('created_at', now()->year)->count() + 1),
                'status' => 'DECLINED',
                'coverage_start' => now()->toDateString(),
                'declined_reason' => $reason,
                'elected_at' => now(),
            ],
        );

        HrBenefitTransaction::query()->create([
            'enrollment_id' => $enrollment->getKey(),
            'employee_id' => $employee->getKey(),
            'plan_id' => $plan->getKey(),
            'transaction_type' => 'DECLINE',
            'effective_date' => now()->toDateString(),
            'actor_user_id' => $actor->getKey(),
            'notes' => $reason,
        ]);

        return $enrollment->refresh();
    }

    public function terminate(HrBenefitEnrollment $enrollment, User $actor, ?string $reason = null): HrBenefitEnrollment
    {
        $enrollment->forceFill([
            'status' => 'TERMINATED',
            'coverage_end' => now()->toDateString(),
        ])->save();

        HrBenefitTransaction::query()->create([
            'enrollment_id' => $enrollment->getKey(),
            'employee_id' => $enrollment->employee_id,
            'plan_id' => $enrollment->plan_id,
            'transaction_type' => 'TERMINATE',
            'effective_date' => now()->toDateString(),
            'actor_user_id' => $actor->getKey(),
            'notes' => $reason,
        ]);

        return $enrollment->refresh();
    }

    /**
     * Kelayakan benefit: gabungan aturan konfigurabel dan syarat pada rencana.
     *
     * @return array{eligible: bool, reasons: list<string>, rules: list<string>}
     */
    public function check(HrEmployee $employee, HrBenefitPlan $plan): array
    {
        $reasons = [];

        $result = $this->eligibility->forBenefit($employee, ['plan_id' => $plan->getKey()]);

        if (! $result['eligible'] && $result['rules'] !== []) {
            $reasons = array_merge($reasons, $result['reasons'] === [] ? ['aturan kelayakan tidak terpenuhi'] : $result['reasons']);
        }

        if ($plan->effective_from !== null && Carbon::parse($plan->effective_from)->isFuture()) {
            $reasons[] = 'rencana belum berlaku';
        }

        if ($plan->waiting_days > 0 && $employee->hire_date !== null) {
            $eligibleDate = Carbon::parse($employee->hire_date)->addDays((int) $plan->waiting_days);

            if ($eligibleDate->isFuture()) {
                $reasons[] = 'masa tunggu belum terlewati (mulai '.$eligibleDate->toDateString().')';
            }
        }

        HrBenefitEligibility::query()->updateOrCreate(
            ['plan_id' => $plan->getKey(), 'employee_id' => $employee->getKey()],
            [
                'organization_id' => $employee->organization_id,
                'employment_type' => $employee->employment_type,
                'worker_type' => $employee->worker_type,
                'is_eligible' => $reasons === [],
                'reason' => $reasons === [] ? null : implode('; ', $reasons),
                'evaluated_at' => now(),
            ],
        );

        return ['eligible' => $reasons === [], 'reasons' => $reasons, 'rules' => $result['rules']];
    }

    /** @return array<string, mixed> */
    public function summary(?int $organizationId = null): array
    {
        $plans = HrBenefitPlan::query()
            ->when($organizationId !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId)))
            ->withCount('enrollments')
            ->get();

        $enrollments = HrBenefitEnrollment::query()->whereIn('status', ['ENROLLED', 'APPROVED'])->get();

        return [
            'plans' => $plans->count(),
            'active_enrollments' => $enrollments->count(),
            'monthly_employee_contribution' => round((float) $enrollments->sum('employee_contribution'), 2),
            'monthly_employer_contribution' => round((float) $enrollments->sum('employer_contribution'), 2),
            'by_plan' => $plans->map(fn (HrBenefitPlan $plan) => [
                'code' => $plan->code,
                'name' => $plan->name,
                'enrollments' => $plan->enrollments_count,
            ])->all(),
        ];
    }
}
