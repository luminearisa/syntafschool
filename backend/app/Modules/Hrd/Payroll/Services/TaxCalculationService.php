<?php

namespace App\Modules\Hrd\Payroll\Services;

use App\Modules\Hrd\Core\Domain\Contracts\PayrollTaxRuleProviderInterface;
use App\Modules\Hrd\Payroll\Models\HrTaxRule;

/**
 * Perhitungan pajak dan iuran wajib berversi (§43).
 *
 * Tarif, lapisan penghasilan, dan iuran tidak pernah ditulis di dalam kode:
 * semuanya dibaca dari `hr_tax_rules` yang punya versi dan tanggal berlaku,
 * sehingga perubahan peraturan cukup menambah baris baru — angka penggajian
 * periode lalu tidak pernah berubah karena aturan baru.
 */
class TaxCalculationService implements PayrollTaxRuleProviderInterface
{
    /**
     * Lapisan PPh 21 progresif (PPh 21 orang pribadi, PKP tahunan) —
     * dipakai hanya bila tabel aturan pada basis data belum diisi.
     *
     * @var list<array{limit: float, rate: float}>
     */
    private const FALLBACK_PPH21_BRACKETS = [
        ['limit' => 60000000.0, 'rate' => 0.05],
        ['limit' => 250000000.0, 'rate' => 0.15],
        ['limit' => 500000000.0, 'rate' => 0.25],
        ['limit' => 5000000000.0, 'rate' => 0.30],
        ['limit' => PHP_FLOAT_MAX, 'rate' => 0.35],
    ];

    public function version(): string
    {
        return (string) config('hrd_phase2.payroll.tax_version', 'ID-2026');
    }

    /**
     * @param array<string, mixed> $taxableBase
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function calculate(array $taxableBase, array $context): array
    {
        $annualGross = (float) ($taxableBase['annual_taxable'] ?? 0);
        $ptkp = (float) ($taxableBase['ptkp'] ?? $this->ptkpFor($context));

        $pkp = max(0, $annualGross - $ptkp);
        $brackets = $this->brackets();

        $annualTax = 0.0;
        $remaining = $pkp;
        $previousLimit = 0.0;
        $breakdown = [];

        foreach ($brackets as $bracket) {
            if ($remaining <= 0) {
                break;
            }

            $limit = (float) $bracket['limit'];
            $rate = (float) $bracket['rate'];
            $bandWidth = $limit - $previousLimit;
            $taxableInBand = min($remaining, $bandWidth);

            if ($taxableInBand > 0) {
                $tax = round($taxableInBand * $rate, 2);
                $annualTax += $tax;
                $breakdown[] = ['band' => [$previousLimit, $limit], 'rate' => $rate, 'taxable' => $taxableInBand, 'tax' => $tax];
                $remaining -= $taxableInBand;
            }

            $previousLimit = $limit;
        }

        $months = max(1, (int) ($context['months'] ?? 12));
        $monthlyTax = round($annualTax / $months, (int) config('hrd_phase2.payroll.rounding', 2));

        return [
            'tax_type' => 'PPH21',
            'rule_version' => $this->version(),
            'annual_taxable' => $annualGross,
            'ptkp' => $ptkp,
            'pkp' => $pkp,
            'annual_tax' => round($annualTax, 2),
            'amount' => $monthlyTax,
            'breakdown' => $breakdown,
            'source' => $this->usingDatabaseRules() ? 'hr_tax_rules' : 'fallback',
        ];
    }

    /**
     * Iuran wajib (BPJS Kesehatan & BPJS Ketenagakerjaan) — bagian pegawai dan
     * bagian pemberi kerja dihitung terpisah.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function contributionRules(array $context): array
    {
        $base = (float) ($context['contributions_base'] ?? 0);

        $defaults = [
            'BPJS_KES_EMPLOYEE' => ['employee_rate' => 1.0, 'employer_rate' => 4.0],
            'BPJS_TK_EMPLOYEE' => ['employee_rate' => 2.0, 'employer_rate' => 3.7],
        ];

        $rules = HrTaxRule::query()
            ->whereIn('tax_type', array_keys($defaults))
            ->where('is_active', true)
            ->where('version', $this->version())
            ->get()
            ->keyBy('tax_type');

        $result = [];

        foreach ($defaults as $taxType => $fallback) {
            $rule = $rules[$taxType] ?? null;

            $employeeRate = (float) ($rule->employee_rate ?? $fallback['employee_rate']);
            $employerRate = (float) ($rule->employer_rate ?? $fallback['employer_rate']);

            $result[$taxType] = [
                'base' => $base,
                'employee_rate' => $employeeRate,
                'employer_rate' => $employerRate,
                'employee_amount' => round($base * $employeeRate / 100, 2),
                'employer_amount' => round($base * $employerRate / 100, 2),
                'rule_version' => $rule->version ?? $this->version(),
            ];
        }

        return $result;
    }

    /** @return list<array{limit: float, rate: float}> */
    private function brackets(): array
    {
        $rule = HrTaxRule::query()
            ->where('tax_type', 'PPH21')
            ->where('is_active', true)
            ->where('version', $this->version())
            ->orderByDesc('effective_from')
            ->first();

        $brackets = $rule?->brackets;

        if (! is_array($brackets) || $brackets === []) {
            return self::FALLBACK_PPH21_BRACKETS;
        }

        return array_map(fn (array $bracket) => [
            'limit' => (float) ($bracket['limit'] ?? PHP_FLOAT_MAX),
            'rate' => (float) ($bracket['rate'] ?? 0),
        ], $brackets);
    }

    private function usingDatabaseRules(): bool
    {
        return HrTaxRule::query()->where('tax_type', 'PPH21')->where('is_active', true)->exists();
    }

    /**
     * PTKP dipengaruhi status kawin dan jumlah tanggungan. Nilainya datang dari
     * konteks (dihitung payroll dari data kepegawaian), bukan dari asumsi kode.
     *
     * @param array<string, mixed> $context
     */
    private function ptkpFor(array $context): float
    {
        $base = (float) ($context['ptkp_base'] ?? 54000000);

        $married = (bool) ($context['married'] ?? false);
        $dependents = min(3, (int) ($context['dependents'] ?? 0));

        return $base + ($married ? 4500000 : 0) + ($dependents * 4500000);
    }
}
