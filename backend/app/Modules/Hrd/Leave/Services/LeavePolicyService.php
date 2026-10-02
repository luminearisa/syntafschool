<?php

namespace App\Modules\Hrd\Leave\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Services\EligibilityEngine;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Leave\Models\HrLeaveAccrual;
use App\Modules\Hrd\Leave\Models\HrLeaveAdjustment;
use App\Modules\Hrd\Leave\Models\HrLeaveBalance;
use App\Modules\Hrd\Leave\Models\HrLeaveEntitlement;
use App\Modules\Hrd\Leave\Models\HrLeavePolicy;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrLeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kebijakan cuti, hak cuti, dan akrual.
 *
 * Kebijakan dipilih dari yang paling spesifik (unit → jenis kepegawaian →
 * golongan → masa kerja) sehingga sekolah dapat memberi aturan berbeda untuk
 * guru tetap dan pegawai kontrak tanpa mengubah kode. Akrual bersifat idempoten:
 * menjalankan penjadwal dua kali pada bulan yang sama tidak menambah saldo dua
 * kali.
 */
class LeavePolicyService
{
    public function __construct(private readonly EligibilityEngine $eligibility) {}

    /** @param array<string, mixed> $attributes */
    public function createPolicy(array $attributes, User $actor): HrLeavePolicy
    {
        $leaveType = HrLeaveType::query()->find($attributes['leave_type_id'] ?? null)
            ?? HrLeaveType::query()->where('code', strtoupper((string) ($attributes['leave_type_code'] ?? '')))->first();

        if ($leaveType === null) {
            throw HrdValidationException::forField('leave_type_id', 'Jenis cuti tidak ditemukan.', 'leave.type_missing');
        }

        return HrLeavePolicy::query()->create([
            'code' => strtoupper((string) ($attributes['code'] ?? 'POL-'.now()->format('YmdHis'))),
            'name' => $attributes['name'] ?? $leaveType->name,
            'leave_type_id' => $leaveType->getKey(),
            'organization_id' => $attributes['organization_id'] ?? null,
            'employment_type' => $attributes['employment_type'] ?? null,
            'worker_type' => $attributes['worker_type'] ?? null,
            'job_grade_code' => $attributes['job_grade_code'] ?? null,
            'min_tenure_months' => (int) ($attributes['min_tenure_months'] ?? 0),
            'gender' => $attributes['gender'] ?? null,
            'entitlement_days' => (float) ($attributes['entitlement_days'] ?? 12),
            'accrual_method' => strtoupper((string) ($attributes['accrual_method'] ?? config('hrd_phase2.leave.default_accrual', 'MONTHLY'))),
            'accrual_rate' => (float) ($attributes['accrual_rate'] ?? round((float) ($attributes['entitlement_days'] ?? 12) / 12, 4)),
            'max_balance' => $attributes['max_balance'] ?? null,
            'carry_forward' => (bool) ($attributes['carry_forward'] ?? false),
            'carry_forward_limit' => $attributes['carry_forward_limit'] ?? null,
            'min_notice_days' => (int) ($attributes['min_notice_days'] ?? config('hrd_phase2.leave.minimum_notice_days', 1)),
            'requires_document' => (bool) ($attributes['requires_document'] ?? false),
            'document_after_days' => (int) ($attributes['document_after_days'] ?? config('hrd_phase2.leave.require_document_after_days', 2)),
            'paid' => (bool) ($attributes['paid'] ?? (bool) $leaveType->is_paid),
            'eligibility_rule_id' => $attributes['eligibility_rule_id'] ?? null,
            'effective_from' => $attributes['effective_from'] ?? now()->startOfYear()->toDateString(),
            'effective_to' => $attributes['effective_to'] ?? null,
            'priority' => (int) ($attributes['priority'] ?? 0),
        ]);
    }

    /**
     * Kebijakan yang berlaku untuk pegawai pada satu jenis cuti.
     *
     * @return array{policy: ?HrLeavePolicy, eligible: bool, reasons: list<string>, specificity: int}
     */
    public function resolvePolicy(HrEmployee $employee, HrLeaveType|string $leaveType, ?string $onDate = null): array
    {
        $type = $leaveType instanceof HrLeaveType ? $leaveType : HrLeaveType::query()->where('code', strtoupper($leaveType))->first();

        if ($type === null) {
            throw HrdValidationException::forField('leave_type', 'Jenis cuti tidak ditemukan.', 'leave.type_missing');
        }

        $onDate = $onDate ?? now()->toDateString();

        $policies = HrLeavePolicy::query()
            ->where('leave_type_id', $type->getKey())
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $onDate))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $onDate))
            ->orderByDesc('priority')
            ->get();

        $best = null;
        $bestScore = -1;

        foreach ($policies as $policy) {
            $score = $this->specificity($policy, $employee);

            if ($score < 0) {
                continue;
            }

            if ($score > $bestScore) {
                $best = $policy;
                $bestScore = $score;
            }
        }

        $eligibility = $this->eligibility->forLeave($employee, ['leave_type_id' => $type->getKey()]);

        return [
            'policy' => $best,
            'eligible' => $best !== null && ($eligibility['eligible'] || $eligibility['rules'] === []),
            'reasons' => $eligibility['reasons'],
            'specificity' => $bestScore,
        ];
    }

    public function periodCode(string|Carbon $date): string
    {
        return Carbon::parse($date)->format('Y-m');
    }

    /**
     * Akrual satu periode (idempoten).
     *
     * @return array<string, mixed>
     */
    public function accrue(HrEmployee $employee, HrLeaveType $leaveType, ?string $periodCode = null, ?User $actor = null): array
    {
        $periodCode = $periodCode ?? $this->periodCode(now());
        $resolved = $this->resolvePolicy($employee, $leaveType);

        $policy = $resolved['policy'];

        if ($policy === null) {
            return ['accrued' => 0.0, 'reason' => 'NO_POLICY'];
        }

        $key = 'leave-accrual:'.$employee->getKey().':'.$leaveType->getKey().':'.$periodCode;

        $existing = HrLeaveAccrual::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return ['accrued' => (float) $existing->accrued_days, 'reason' => 'ALREADY_ACCRUED', 'accrual_id' => $existing->getKey()];
        }

        $accrued = $this->accruedFor($policy, $employee, $periodCode);

        return DB::transaction(function () use ($employee, $leaveType, $policy, $periodCode, $accrued, $key, $actor) {
            $accrual = HrLeaveAccrual::query()->create([
                'employee_id' => $employee->getKey(),
                'leave_type_id' => $leaveType->getKey(),
                'period_code' => $periodCode,
                'accrued_days' => $accrued,
                'accrual_date' => Carbon::parse($periodCode.'-01')->endOfMonth()->toDateString(),
                'method' => (string) $policy->accrual_method,
                'idempotency_key' => $key,
                'created_by' => $actor?->getKey(),
            ]);

            HrLeaveEntitlement::query()->updateOrCreate(
                ['employee_id' => $employee->getKey(), 'leave_type_id' => $leaveType->getKey(), 'period_start' => ''.Carbon::parse($periodCode.'-01')->startOfYear()->toDateString().''],
                [
                    'policy_id' => $policy->getKey(),
                    'period_end' => Carbon::parse($periodCode.'-01')->endOfYear()->toDateString(),
                    'entitled_days' => (float) $policy->entitlement_days,
                    'accrual_rate' => (float) $policy->accrual_rate,
                    'source' => 'POLICY',
                    'computed_at' => now(),
                ],
            );

            $balance = $this->balanceFor($employee->getKey(), $leaveType->getKey(), $periodCode);

            $balance->forceFill([
                'accrued_days' => round((float) $balance->accrued_days + $accrued, 4),
                'entitlement_days' => max((float) $balance->entitlement_days, (float) $policy->entitlement_days),
                'last_accrued_at' => now(),
            ])->save();

            $this->refreshAvailable($balance->refresh());

            return ['accrued' => $accrued, 'reason' => 'ACCRUED', 'accrual_id' => $accrual->getKey(), 'balance_id' => $balance->getKey()];
        });
    }

    /**
     * Akrual massal untuk penjadwal bulanan.
     *
     * @return array<string, int>
     */
    public function accrueAll(?string $periodCode = null, ?User $actor = null): array
    {
        $periodCode = $periodCode ?? $this->periodCode(now());
        $types = HrLeaveType::query()->where(fn ($query) => $query->where('is_active', true)->orWhereNull('is_active'))->get();

        $accrued = 0;
        $skipped = 0;
        $employees = HrEmployee::query()->where('lifecycle_state', 'active')->get();

        foreach ($employees as $employee) {
            foreach ($types as $type) {
                $result = $this->accrue($employee, $type, $periodCode, $actor);

                if (($result['reason'] ?? null) === 'ACCRUED') {
                    $accrued++;
                } else {
                    $skipped++;
                }
            }
        }

        return ['period' => $periodCode, 'accrued' => $accrued, 'skipped' => $skipped, 'employees' => $employees->count()];
    }

    public function balanceFor(int $employeeId, int $leaveTypeId, ?string $periodCode = null): HrLeaveBalance
    {
        $periodCode = $periodCode ?? $this->periodCode(now());

        $balance = HrLeaveBalance::query()->firstOrCreate(
            ['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'period_code' => $periodCode],
            ['available_days' => 0],
        );

        if ($balance->wasRecentlyCreated) {
            $this->carryForwardFromPrevious($balance);
        }

        return $balance->refresh();
    }

    /**
     * Saldo periode baru mewarisi sisa periode sebelumnya sebatas kebijakan.
     *
     * Tanpa langkah ini, cuti yang diajukan untuk bulan berikutnya akan selalu
     * ditolak walau hak cutinya tersedia — kesalahan yang paling sering muncul
     * pada aplikasi cuti sederhana.
     */
    private function carryForwardFromPrevious(HrLeaveBalance $balance): void
    {
        $previous = HrLeaveBalance::query()
            ->where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('period_code', '<', $balance->period_code)
            ->orderByDesc('period_code')
            ->first();

        if ($previous === null) {
            return;
        }

        $policy = HrLeavePolicy::query()
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->first();

        $carry = (bool) ($policy->carry_forward ?? false) ? (float) $previous->available_days : 0.0;
        $limit = $policy?->carry_forward_limit !== null ? (float) $policy->carry_forward_limit : null;

        if ($limit !== null) {
            $carry = min($carry, $limit);
        }

        $balance->forceFill([
            'entitlement_days' => (float) $previous->entitlement_days,
            'carry_forward_days' => round($carry, 4),
        ])->save();

        $this->refreshAvailable($balance->refresh());
    }

    /** @return list<array<string, mixed>> */
    public function balances(int $employeeId, ?string $periodCode = null): array
    {
        $periodCode = $periodCode ?? $this->periodCode(now());
        $types = HrLeaveType::query()->get()->keyBy('id');

        return HrLeaveBalance::query()
            ->where('employee_id', $employeeId)
            ->where('period_code', $periodCode)
            ->get()
            ->map(fn (HrLeaveBalance $balance) => [
                'leave_type' => $types[$balance->leave_type_id]->name ?? null,
                'code' => $types[$balance->leave_type_id]->code ?? null,
                'entitlement' => (float) $balance->entitlement_days,
                'accrued' => (float) $balance->accrued_days,
                'used' => (float) $balance->used_days,
                'pending' => (float) $balance->pending_days,
                'carry_forward' => (float) $balance->carry_forward_days,
                'expired' => (float) $balance->expired_days,
                'adjustment' => (float) $balance->adjustment_days,
                'available' => (float) $balance->available_days,
            ])
            ->all();
    }

    public function applyPending(int $employeeId, int $leaveTypeId, float $days, ?string $periodCode = null): void
    {
        $balance = $this->balanceFor($employeeId, $leaveTypeId, $periodCode);
        $balance->forceFill(['pending_days' => round((float) $balance->pending_days + $days, 4)])->save();
        $this->refreshAvailable($balance->refresh());
    }

    public function consume(int $employeeId, int $leaveTypeId, float $days, ?string $periodCode = null): void
    {
        $balance = $this->balanceFor($employeeId, $leaveTypeId, $periodCode);
        $balance->forceFill([
            'pending_days' => max(0, round((float) $balance->pending_days - $days, 4)),
            'used_days' => round((float) $balance->used_days + $days, 4),
        ])->save();
        $this->refreshAvailable($balance->refresh());
    }

    public function release(int $employeeId, int $leaveTypeId, float $days, ?string $periodCode = null): void
    {
        $balance = $this->balanceFor($employeeId, $leaveTypeId, $periodCode);
        $balance->forceFill([
            'pending_days' => max(0, round((float) $balance->pending_days - $days, 4)),
            'used_days' => max(0, round((float) $balance->used_days - $days, 4)),
        ])->save();
        $this->refreshAvailable($balance->refresh());
    }

    public function adjust(int $employeeId, int $leaveTypeId, float $days, string $type, string $reason, User $actor, ?string $periodCode = null): HrLeaveAdjustment
    {
        $periodCode = $periodCode ?? $this->periodCode(now());

        return DB::transaction(function () use ($employeeId, $leaveTypeId, $days, $type, $reason, $actor, $periodCode) {
            $balance = $this->balanceFor($employeeId, $leaveTypeId, $periodCode);

            $adjustment = HrLeaveAdjustment::query()->create([
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'period_code' => $periodCode,
                'adjustment_type' => strtoupper($type),
                'days' => $days,
                'reason' => $reason,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'applied_at' => now(),
                'balance_id' => $balance->getKey(),
            ]);

            $column = match (strtoupper($type)) {
                'CARRY_FORWARD' => 'carry_forward_days',
                'EXPIRY' => 'expired_days',
                default => 'adjustment_days',
            };

            $balance->forceFill([$column => round((float) $balance->{$column} + $days, 4)])->save();
            $this->refreshAvailable($balance->refresh());

            return $adjustment;
        });
    }

    private function refreshAvailable(HrLeaveBalance $balance): HrLeaveBalance
    {
        $entitlement = max((float) $balance->entitlement_days, (float) $balance->accrued_days);
        $available = $entitlement
            + (float) $balance->carry_forward_days
            + (float) $balance->adjustment_days
            - (float) $balance->used_days
            - (float) $balance->pending_days
            - (float) $balance->expired_days;

        $balance->forceFill(['available_days' => round(max($available, 0), 4)])->save();

        return $balance->refresh();
    }

    private function accruedFor(HrLeavePolicy $policy, HrEmployee $employee, string $periodCode): float
    {
        $month = Carbon::parse($periodCode.'-01');

        return match (strtoupper((string) $policy->accrual_method)) {
            'NONE' => 0.0,
            'ANNUAL', 'CALENDAR_YEAR' => $month->month === 1 ? (float) $policy->entitlement_days : 0.0,
            'ANNIVERSARY' => $employee->hire_date !== null && Carbon::parse($employee->hire_date)->month === $month->month
                ? (float) $policy->entitlement_days
                : 0.0,
            'PRO_RATA' => round((float) $policy->entitlement_days / 12 * $this->monthsWorked($employee, $month), 4),
            default => (float) $policy->accrual_rate,
        };
    }

    private function monthsWorked(HrEmployee $employee, Carbon $month): int
    {
        if ($employee->hire_date === null) {
            return 12;
        }

        $hire = Carbon::parse($employee->hire_date);

        if ($hire->greaterThan($month->copy()->endOfMonth())) {
            return 0;
        }

        return min(12, (int) $hire->diffInMonths($month->copy()->startOfMonth()) + 1);
    }

    /** Skor kekhususan kebijakan; -1 berarti kebijakan tidak berlaku untuk pegawai ini. */
    private function specificity(HrLeavePolicy $policy, HrEmployee $employee): int
    {
        $score = $policy->priority ?? 0;

        if ($policy->organization_id !== null) {
            if ((int) $policy->organization_id !== (int) $employee->organization_id) {
                return -1;
            }

            $score += 100;
        }

        if ($policy->employment_type !== null) {
            if (strcasecmp((string) $policy->employment_type, (string) $employee->employment_type) !== 0) {
                return -1;
            }

            $score += 50;
        }

        if ($policy->worker_type !== null) {
            if (strcasecmp((string) $policy->worker_type, (string) $employee->worker_type) !== 0) {
                return -1;
            }

            $score += 25;
        }

        if ($policy->gender !== null && strcasecmp((string) $policy->gender, (string) ($employee->person?->gender ?? '')) !== 0) {
            return -1;
        }

        if ((int) $policy->min_tenure_months > 0 && $employee->hire_date !== null) {
            if (Carbon::parse($employee->hire_date)->diffInMonths(now()) < (int) $policy->min_tenure_months) {
                return -1;
            }

            $score += 10;
        }

        return $score;
    }
}
