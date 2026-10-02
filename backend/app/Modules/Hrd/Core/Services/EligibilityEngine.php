<?php

namespace App\Modules\Hrd\Core\Services;

use App\Modules\Hrd\Models\HrEmployee;

/**
 * Mesin kelayakan bersama (§107).
 *
 * Cuti, benefit, pelatihan, karier, lowongan internal, dan kompensasi memakai
 * satu mesin yang sama. Karena itu aturan seperti "hanya pegawai tetap dengan
 * masa kerja > 1 tahun" cukup ditulis sekali dan dipakai di mana pun — bukan
 * disalin ke setiap layanan.
 */
class EligibilityEngine
{
    public const BENEFIT = 'BENEFIT_ELIGIBILITY';
    public const LEAVE = 'LEAVE_ELIGIBILITY';
    public const TRAINING = 'TRAINING_REQUIREMENT';
    public const CAREER = 'CAREER_ELIGIBILITY';
    public const COMPENSATION = 'COMPENSATION_ELIGIBILITY';
    public const INTERNAL_JOB = 'INTERNAL_JOB_ELIGIBILITY';
    public const ALERT = 'ALERT';

    public function __construct(private readonly RuleEngine $rules) {}

    /**
     * @param array<string, mixed> $extra
     * @return array{eligible: bool, reasons: list<string>, rules: list<string>, trace?: array<string, mixed>}
     */
    public function check(string $purpose, HrEmployee $employee, array $extra = [], bool $withTrace = false): array
    {
        $matched = $this->rules->evaluate($purpose, $employee, $extra, $withTrace);

        $eligible = false;
        $reasons = [];
        $codes = [];

        foreach ($matched as $rule) {
            $action = strtoupper((string) $rule->action_type);
            $payload = is_array($rule->action_payload) ? $rule->action_payload : [];

            if ($action === 'ELIGIBLE' || $action === 'REQUIRE' || $action === 'APPLY') {
                $eligible = true;
            }

            if ($action === 'NOT_ELIGIBLE' || $action === 'BLOCK') {
                $eligible = false;
                $reasons[] = (string) ($payload['reason'] ?? $rule->name);
            }

            $codes[] = (string) $rule->code;
        }

        $result = [
            'eligible' => $eligible,
            'reasons' => $reasons,
            'rules' => $codes,
        ];

        if ($withTrace) {
            $result['trace'] = $this->rules->explain($purpose, $employee, $extra);
        }

        return $result;
    }

    /** @param array<string, mixed> $extra */
    public function forLeave(HrEmployee $employee, array $extra = []): array
    {
        return $this->check(self::LEAVE, $employee, $extra);
    }

    /** @param array<string, mixed> $extra */
    public function forBenefit(HrEmployee $employee, array $extra = []): array
    {
        return $this->check(self::BENEFIT, $employee, $extra);
    }

    /** @param array<string, mixed> $extra */
    public function forTraining(HrEmployee $employee, array $extra = []): array
    {
        return $this->check(self::TRAINING, $employee, $extra);
    }

    /** @param array<string, mixed> $extra */
    public function forCareer(HrEmployee $employee, array $extra = []): array
    {
        return $this->check(self::CAREER, $employee, $extra);
    }

    /** @param array<string, mixed> $extra */
    public function forInternalJob(HrEmployee $employee, array $extra = []): array
    {
        return $this->check(self::INTERNAL_JOB, $employee, $extra);
    }

    /** @param array<string, mixed> $extra */
    public function forCompensation(HrEmployee $employee, array $extra = []): array
    {
        return $this->check(self::COMPENSATION, $employee, $extra);
    }
}
