<?php

namespace App\Modules\Hrd\Payroll\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Domain\Contracts\FinancePostingProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\PayrollProviderInterface;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Leave\Services\LeaveRequestService;
use App\Modules\Hrd\Payroll\Models\HrPayrollApproval;
use App\Modules\Hrd\Payroll\Models\HrPayrollItem;
use App\Modules\Hrd\Payroll\Models\HrPayrollItemComponent;
use App\Modules\Hrd\Payroll\Models\HrPayrollPeriod;
use App\Modules\Hrd\Payroll\Models\HrPayrollPosting;
use App\Modules\Hrd\Payroll\Models\HrPayrollRun;
use App\Modules\Hrd\Payroll\Models\HrPayrollSnapshot;
use App\Modules\Hrd\Payroll\Models\HrPayrollTax;
use App\Modules\Hrd\Payroll\Models\HrPayslip;
use App\Modules\Hrd\Payroll\Models\HrSalaryComponent;
use App\Modules\Hrd\Workforce\Services\AttendanceService;
use App\Modules\Hrd\Workforce\Services\OvertimeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mesin penggajian.
 *
 * Alur lengkapnya: periode → jalankan → hitung (struktur + presensi + cuti +
 * lembur + benefit + pajak) → tinjau → setujui → finalkan (snapshot beku +
 * slip) → posting ke Finance.
 *
 * Tiga janji yang dijaga di sini:
 *  1. Angka yang sudah final tidak pernah berubah diam-diam; koreksi memakai
 *     transaksi penyesuaian.
 *  2. Orang yang menyiapkan penggajian bukan orang yang menyetujuinya.
 *  3. Total dan selisih antar periode diperiksa sebelum pengesahan, bukan
 *     sesudahnya.
 */
class PayrollService implements PayrollProviderInterface
{
    public function __construct(
        private readonly SalaryStructureService $structures,
        private readonly TaxCalculationService $tax,
        private readonly AttendanceService $attendance,
        private readonly LeaveRequestService $leave,
        private readonly OvertimeService $overtime,
        private readonly HrdPhase2Events $events,
    ) {}

    // ------------------------------------------------------------------ periode

    /** @param array<string, mixed> $attributes */
    public function openPeriod(array $attributes, User $actor): HrPayrollPeriod
    {
        $start = Carbon::parse($attributes['start_date'] ?? now()->startOfMonth()->toDateString());
        $end = Carbon::parse($attributes['end_date'] ?? $start->copy()->endOfMonth()->toDateString());

        if ($end->lessThan($start)) {
            throw HrdValidationException::forField('end_date', 'Tanggal akhir periode tidak boleh sebelum awal.', 'payroll.period_invalid');
        }

        $code = (string) ($attributes['code'] ?? $start->format('Y-m'));

        return HrPayrollPeriod::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $attributes['name'] ?? 'Gaji '.$start->translatedFormat('F Y'),
                'period_type' => strtoupper((string) ($attributes['period_type'] ?? config('hrd_phase2.payroll.default_period', 'MONTHLY'))),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'pay_date' => $attributes['pay_date'] ?? $end->copy()->addDays(5)->toDateString(),
                'cutoff_date' => $attributes['cutoff_date'] ?? $end->toDateString(),
                'status' => 'OPEN',
                'organization_id' => $attributes['organization_id'] ?? null,
                'working_days' => (int) ($attributes['working_days'] ?? config('hrd_phase2.payroll.working_days_per_month', 22)),
            ],
        );
    }

    // ------------------------------------------------------------------ menjalankan

    /**
     * Menjalankan penggajian satu periode. Idempoten: menjalankan ulang dengan
     * kunci yang sama mengembalikan proses yang sudah ada, bukan menggandakan.
     *
     * @param array<string, mixed> $attributes
     */
    public function createRun(HrPayrollPeriod|int $period, array $attributes, User $actor): HrPayrollRun
    {
        $periodModel = $period instanceof HrPayrollPeriod ? $period : HrPayrollPeriod::query()->findOrFail($period);

        if ($periodModel->is_locked) {
            throw HrdValidationException::forField('period', 'Periode ini sudah dikunci.', 'payroll.period_locked');
        }

        $runType = strtoupper((string) ($attributes['run_type'] ?? 'REGULAR'));
        $organizationId = $attributes['organization_id'] ?? null;
        $key = (string) ($attributes['idempotency_key'] ?? 'payroll-run:'.$periodModel->code.':'.$runType.':'.($organizationId ?? 'ALL'));

        $existing = HrPayrollRun::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($periodModel, $attributes, $actor, $runType, $organizationId, $key) {
            $run = HrPayrollRun::query()->create([
                'code' => sprintf('PR-%s-%03d', $periodModel->code, HrPayrollRun::query()->where('period_id', $periodModel->getKey())->count() + 1),
                'period_id' => $periodModel->getKey(),
                'organization_id' => $organizationId,
                'department_id' => $attributes['department_id'] ?? null,
                'run_type' => $runType,
                'status' => 'DRAFT',
                'idempotency_key' => $key,
                'prepared_by' => $actor->getKey(),
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->events->emit('PAYROLL', 'payroll.run_created', [
                'actor' => $actor,
                'subject_type' => HrPayrollRun::class,
                'subject_id' => $run->getKey(),
                'title' => 'Penggajian '.$run->code.' disiapkan',
                'payload' => ['period' => $periodModel->code, 'run_type' => $runType],
            ]);

            return $run;
        });
    }

    /**
     * Mesin perhitungan (§39). Semua masukan — gaji, presensi, cuti, lembur,
     * benefit, pajak — dibaca pada saat ini dan disimpan ke baris item.
     */
    public function calculate(HrPayrollRun $run, User $actor): HrPayrollRun
    {
        $period = $run->period;

        if ($period === null) {
            throw HrdValidationException::forField('period_id', 'Periode penggajian tidak ditemukan.', 'payroll.period_missing');
        }

        if (in_array($run->status, ['FINALIZED', 'POSTED', 'APPROVED'], true)) {
            throw HrdValidationException::forField('status', 'Penggajian yang sudah disetujui tidak dapat dihitung ulang.', 'payroll.run_locked');
        }

        $start = (string) $period->start_date?->toDateString();
        $end = (string) $period->end_date?->toDateString();
        $workingDays = max(1, (int) ($period->working_days ?: config('hrd_phase2.payroll.working_days_per_month', 22)));
        $hoursPerDay = (float) config('hrd_phase2.payroll.working_hours_per_day', 8);
        $rounding = (int) config('hrd_phase2.payroll.rounding', 2);

        return DB::transaction(function () use ($run, $actor, $start, $end, $workingDays, $hoursPerDay, $rounding) {
            $run->forceFill(['status' => 'PROCESSING'])->save();

            $employees = $this->employeesFor($run);
            $totals = [
                'employee_count' => 0, 'gross_total' => 0.0, 'earning_total' => 0.0,
                'deduction_total' => 0.0, 'tax_total' => 0.0, 'benefit_total' => 0.0,
                'net_total' => 0.0, 'employer_cost_total' => 0.0,
            ];
            $skipped = [];

            foreach ($employees as $employee) {
                $structure = $this->structures->componentsFor((int) $employee->getKey(), $start, $end);

                if ($structure['assignment'] === null && $structure['earning'] === [] && $structure['base_amount'] <= 0) {
                    $skipped[] = ['employee_id' => $employee->getKey(), 'reason' => 'NO_SALARY_STRUCTURE'];

                    continue;
                }

                $calculated = $this->calculateFor($employee, $structure, $start, $end, $workingDays, $hoursPerDay, $rounding, $run);

                $item = $calculated['item'];

                $totals['employee_count']++;
                $totals['gross_total'] += (float) $item->gross_amount;
                $totals['earning_total'] += (float) $item->earning_total;
                $totals['deduction_total'] += (float) $item->deduction_total;
                $totals['tax_total'] += (float) $item->tax_total;
                $totals['benefit_total'] += (float) $item->benefit_total;
                $totals['net_total'] += (float) $item->net_amount;
                $totals['employer_cost_total'] += $calculated['employer_cost'];
            }

            $run->forceFill(array_merge($totals, [
                'status' => 'REVIEW',
                'meta' => array_merge(is_array($run->meta) ? $run->meta : [], ['skipped' => $skipped, 'calculated_at' => now()->toIso8601String()]),
            ]))->save();

            $variance = $this->reconciliation($run->refresh());
            $run->forceFill(['variance_snapshot' => $variance['variance']])->save();

            $this->events->emit('PAYROLL', 'payroll.calculated', [
                'actor' => $actor,
                'subject_type' => HrPayrollRun::class,
                'subject_id' => $run->getKey(),
                'title' => 'Penggajian '.$run->code.' dihitung ('.$totals['employee_count'].' pegawai)',
                'payload' => $totals + ['skipped' => count($skipped)],
            ]);

            return $run->refresh();
        });
    }

    /**
     * Perhitungan satu pegawai.
     *
     * @param array<string, mixed> $structure
     * @return array{item: HrPayrollItem, employer_cost: float}
     */
    private function calculateFor(HrEmployee $employee, array $structure, string $start, string $end, int $workingDays, float $hoursPerDay, int $rounding, HrPayrollRun $run): array
    {
        $base = (float) $structure['base_amount'];
        $dailyRate = $workingDays > 0 ? $base / $workingDays : 0.0;

        $attendance = $this->attendance->attendanceSummary((int) $employee->getKey(), $start, $end);
        $absentDays = (float) $this->attendance->absenceDays((int) $employee->getKey(), $start, $end);
        $unpaidLeaveDays = (float) $this->leave->unpaidLeaveDays((int) $employee->getKey(), $start, $end);
        $unpaidDays = $absentDays + $unpaidLeaveDays;
        $overtime = $this->overtime->payableFor((int) $employee->getKey(), $start, $end);

        $presentDays = max(0, $workingDays - (int) ceil($unpaidDays));
        $attendanceRatio = $workingDays > 0 ? $presentDays / $workingDays : 1.0;

        $earningRows = [];
        $deductionRows = [];
        $benefitRows = [];
        $employerRows = [];

        $earningRows[] = [
            'component_code' => 'BASIC',
            'component_name' => 'Gaji Pokok',
            'kind' => 'EARNING',
            'amount' => round(max(0, $base - ($dailyRate * $unpaidDays)), $rounding),
            'quantity' => $presentDays,
            'rate' => round($dailyRate, $rounding),
            'is_prorated' => true,
            'is_taxable' => true,
            'source' => 'STRUCTURE',
            'reference' => [
                'base_amount' => $base,
                'unpaid_days' => $unpaidDays,
                'absent_days' => $absentDays,
                'unpaid_leave_days' => $unpaidLeaveDays,
                'working_days' => $workingDays,
            ],
        ];

        foreach ($structure['earning'] as $code => $amount) {
            // Gaji pokok sudah diwakili baris BASIC yang diprorata di atas;
            // menghitungnya lagi dari struktur akan menggandakan pokok.
            if (strtoupper((string) $code) === 'BASIC') {
                continue;
            }

            $component = HrSalaryComponent::query()->where('code', $code)->first();
            $prorated = (bool) ($component?->is_prorated ?? false);

            $earningRows[] = [
                'component_code' => $code,
                'component_name' => $component?->name ?? $code,
                'kind' => 'EARNING',
                'amount' => round($prorated ? $amount * $attendanceRatio : $amount, $rounding),
                'is_prorated' => $prorated,
                'is_taxable' => (bool) ($component?->is_taxable ?? true),
                'source' => 'STRUCTURE',
            ];
        }

        $overtimeAmount = 0.0;

        if ((float) $overtime['total_hours'] > 0) {
            $hourlyRate = $hoursPerDay > 0 ? $dailyRate / $hoursPerDay : 0.0;

            foreach ($overtime['records'] as $record) {
                $minutes = (float) ($record['verified_minutes'] ?? 0);
                $amount = $record['amount'] !== null
                    ? (float) $record['amount']
                    : round($minutes / 60 * $hourlyRate * (float) ($record['multiplier'] ?? 1.5), $rounding);

                $overtimeAmount += $amount;

                if ($record['amount'] === null) {
                    // Payroll adalah satu-satunya pihak yang menetapkan nilai uang
                    // lembur, sehingga angka pada catatan lembur selalu sama dengan
                    // angka yang benar-benar dibayarkan.
                    \App\Modules\Hrd\Workforce\Models\HrOvertimeRecord::query()
                        ->where('employee_id', $employee->getKey())
                        ->where('date', $record['date'])
                        ->update(['rate_amount' => round($hourlyRate, $rounding), 'amount' => $amount]);
                }
            }

            $earningRows[] = [
                'component_code' => 'OVERTIME',
                'component_name' => 'Lembur',
                'kind' => 'EARNING',
                'amount' => round($overtimeAmount, $rounding),
                'quantity' => (float) $overtime['total_hours'],
                'rate' => round($hourlyRate, $rounding),
                'is_taxable' => true,
                'source' => 'OVERTIME',
                'reference' => ['minutes' => (int) $overtime['total_minutes']],
            ];
        }

        foreach ($structure['deduction'] as $code => $amount) {
            $component = HrSalaryComponent::query()->where('code', $code)->first();

            $deductionRows[] = [
                'component_code' => $code,
                'component_name' => $component?->name ?? $code,
                'kind' => 'DEDUCTION',
                'amount' => round((float) $amount, $rounding),
                'is_taxable' => false,
                'source' => str_contains($code, 'BENEFIT') ? 'BENEFIT' : 'STRUCTURE',
            ];
        }

        foreach ($structure['benefit'] as $code => $amount) {
            $component = HrSalaryComponent::query()->where('code', $code)->first();
            $benefitRows[] = [
                'component_code' => $code,
                'component_name' => $component?->name ?? $code,
                'kind' => 'BENEFIT',
                'amount' => round((float) $amount, $rounding),
                'is_taxable' => false,
                'source' => 'BENEFIT',
            ];
        }

        foreach ($structure['employer'] as $code => $amount) {
            $component = HrSalaryComponent::query()->where('code', $code)->first();
            $employerRows[] = [
                'component_code' => $code,
                'component_name' => $component?->name ?? $code,
                'kind' => 'EMPLOYER_CONTRIBUTION',
                'amount' => round((float) $amount, $rounding),
                'is_taxable' => false,
                'source' => 'STATUTORY',
            ];
        }

        if (in_array($run->run_type, ['THR', 'BONUS'], true)) {
            $earningRows[] = [
                'component_code' => $run->run_type,
                'component_name' => $run->run_type === 'THR' ? 'Tunjangan Hari Raya' : 'Bonus',
                'kind' => 'EARNING',
                'amount' => round($base, $rounding),
                'is_taxable' => true,
                'source' => 'STRUCTURE',
            ];
        }

        $taxableEarnings = array_sum(array_map(fn (array $row) => ($row['is_taxable'] ?? true) ? (float) $row['amount'] : 0.0, $earningRows));
        $gross = array_sum(array_map(fn (array $row) => (float) $row['amount'], $earningRows));
        $deductionTotal = array_sum(array_map(fn (array $row) => (float) $row['amount'], $deductionRows));
        $benefitTotal = array_sum(array_map(fn (array $row) => (float) $row['amount'], $benefitRows));

        $taxContext = $this->taxContextFor($employee);
        $taxResult = $this->tax->calculate([
            'annual_taxable' => $taxableEarnings * 12,
            'ptkp' => $taxContext['ptkp_base'] + ($taxContext['married'] ? 4500000 : 0) + ($taxContext['dependents'] * 4500000),
        ], $taxContext);

        $contributions = $this->tax->contributionRules(['contributions_base' => min($base, 12000000)]);

        $taxRows = [[
            'component_code' => 'PPH21',
            'component_name' => 'PPh 21',
            'kind' => 'TAX',
            'amount' => round((float) $taxResult['amount'], $rounding),
            'is_taxable' => false,
            'source' => 'TAX',
            'reference' => ['rule_version' => $taxResult['rule_version'], 'pkp' => $taxResult['pkp']],
        ]];

        foreach ($contributions as $taxType => $contribution) {
            $taxRows[] = [
                'component_code' => $taxType,
                'component_name' => str_replace('_', ' ', $taxType),
                'kind' => 'DEDUCTION',
                'amount' => (float) $contribution['employee_amount'],
                'is_taxable' => false,
                'source' => 'STATUTORY',
                'reference' => ['rule_version' => $contribution['rule_version']],
            ];
        }

        $taxTotal = array_sum(array_map(fn (array $row) => (float) $row['amount'], $taxRows));
        $net = round($gross - $deductionTotal - $taxTotal, $rounding);
        $employerCost = array_sum(array_map(fn (array $row) => (float) $row['amount'], $employerRows))
            + array_sum(array_map(fn (array $row) => (float) $row['employer_amount'], $contributions));

        $item = HrPayrollItem::query()->updateOrCreate(
            ['run_id' => $run->getKey(), 'employee_id' => $employee->getKey()],
            [
                'employee_number' => $employee->employee_number,
                'employee_name' => $employee->displayName() ?? $employee->employee_number,
                'organization_id' => $employee->organization_id,
                'department_id' => $employee->department_id,
                'position_id' => $employee->position_id,
                'employment_type' => $employee->employment_type,
                'base_amount' => round($base, $rounding),
                'earning_total' => round($gross, $rounding),
                'deduction_total' => round($deductionTotal, $rounding),
                'tax_total' => round($taxTotal, $rounding),
                'benefit_total' => round($benefitTotal, $rounding),
                'overtime_amount' => round($overtimeAmount, $rounding),
                'gross_amount' => round($gross, $rounding),
                'net_amount' => $net,
                'prorated_days' => $presentDays,
                'absent_days' => $absentDays,
                'unpaid_leave_days' => $unpaidLeaveDays,
                'overtime_minutes' => (int) ($overtime['total_minutes'] ?? 0),
                'currency' => 'IDR',
                'status' => 'CALCULATED',
                'calculated_at' => now(),
                'snapshot' => [
                    'structure_assignment_id' => $structure['assignment']?->getKey(),
                    'base_amount' => $base,
                    'attendance' => $attendance,
                    'unpaid_days' => $unpaidDays,
                    'overtime' => $overtime,
                    'tax' => $taxResult,
                    'contributions' => $contributions,
                ],
            ],
        );

        $item->components()->delete();

        foreach (array_merge($earningRows, $deductionRows, $benefitRows, $employerRows, $taxRows) as $row) {
            $component = HrSalaryComponent::query()->where('code', $row['component_code'])->first();

            HrPayrollItemComponent::query()->create([
                'item_id' => $item->getKey(),
                'component_id' => $component?->getKey(),
                'component_code' => $row['component_code'],
                'component_name' => $row['component_name'],
                'kind' => $row['kind'],
                'amount' => $row['amount'],
                'quantity' => $row['quantity'] ?? null,
                'rate' => $row['rate'] ?? null,
                'is_prorated' => (bool) ($row['is_prorated'] ?? false),
                'is_taxable' => (bool) ($row['is_taxable'] ?? true),
                'source' => $row['source'] ?? null,
                'reference' => $row['reference'] ?? null,
            ]);
        }

        foreach ($taxRows as $row) {
            HrPayrollTax::query()->create([
                'run_id' => $run->getKey(),
                'item_id' => $item->getKey(),
                'employee_id' => $employee->getKey(),
                'period_code' => (string) $run->period?->code,
                'tax_type' => str_starts_with($row['component_code'], 'PPH') ? 'PPH21' : $row['component_code'],
                'taxable_base' => $taxableEarnings,
                'rate' => 0,
                'amount' => $row['amount'],
                'rule_version' => $taxResult['rule_version'],
                'meta' => $row['reference'] ?? null,
            ]);
        }

        return ['item' => $item->refresh(), 'employer_cost' => round($employerCost, $rounding)];
    }

    // ------------------------------------------------------------------ kendali & pengesahan

    public function review(HrPayrollRun $run, User $actor, bool $approved, ?string $notes = null): HrPayrollRun
    {
        $this->assertSeparation($run, $actor, 'CHECKER');

        // Telaah tidak mengubah status menjadi disetujui: ia hanya menandai
        // bahwa proses sudah diperiksa. Persetujuan adalah langkah terpisah
        // dengan pelakunya sendiri.
        $run->forceFill([
            'status' => $approved ? 'REVIEW' : 'DRAFT',
            'reviewed_by' => $approved ? $actor->getKey() : null,
            'reviewed_at' => $approved ? now() : null,
        ])->save();

        $this->recordApproval($run, 'REVIEW', 'CHECKER', $actor, $approved ? 'APPROVED' : 'REJECTED', $notes);

        $this->events->emit('PAYROLL', $approved ? 'payroll.reviewed' : 'payroll.review_rejected', [
            'actor' => $actor,
            'subject_type' => HrPayrollRun::class,
            'subject_id' => $run->getKey(),
            'title' => 'Penggajian '.$run->code.' '.($approved ? 'lolos telaah' : 'dikembalikan'),
            'reason' => $notes,
        ]);

        return $run->refresh();
    }

    public function approve(HrPayrollRun $run, User $actor, ?string $notes = null): HrPayrollRun
    {
        $this->assertSeparation($run, $actor, 'APPROVER');

        if ($run->status !== 'REVIEW' || $run->reviewed_by === null) {
            throw HrdValidationException::forField('status', 'Penggajian harus lolos telaah sebelum disetujui.', 'payroll.review_required');
        }

        $run->forceFill([
            'status' => 'APPROVED',
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
        ])->save();

        $this->recordApproval($run, 'APPROVE', 'APPROVER', $actor, 'APPROVED', $notes);

        $this->events->emit('PAYROLL', 'payroll.approved', [
            'actor' => $actor,
            'subject_type' => HrPayrollRun::class,
            'subject_id' => $run->getKey(),
            'title' => 'Penggajian '.$run->code.' disetujui',
            'reason' => $notes,
        ]);

        return $run->refresh();
    }

    /**
     * Finalisasi: membuat snapshot beku dan slip gaji. Setelah ini angka tidak
     * dapat berubah kecuali lewat penyesuaian.
     */
    public function finalize(HrPayrollRun $run, User $actor): HrPayrollRun
    {
        $this->assertSeparation($run, $actor, 'FINALIZER');

        if ($run->status !== 'APPROVED') {
            throw HrdValidationException::forField('status', 'Hanya penggajian yang disetujui dapat difinalkan.', 'payroll.not_approved');
        }

        return DB::transaction(function () use ($run, $actor) {
            foreach ($run->items as $item) {
                $payload = [
                    'employee' => [
                        'id' => $item->employee_id,
                        'number' => $item->employee_number,
                        'name' => $item->employee_name,
                        'organization_id' => $item->organization_id,
                        'department_id' => $item->department_id,
                        'position_id' => $item->position_id,
                        'employment_type' => $item->employment_type,
                    ],
                    'period' => [
                        'code' => $run->period?->code,
                        'start' => $run->period?->start_date?->toDateString(),
                        'end' => $run->period?->end_date?->toDateString(),
                    ],
                    'amounts' => [
                        'base' => (float) $item->base_amount,
                        'earning' => (float) $item->earning_total,
                        'deduction' => (float) $item->deduction_total,
                        'tax' => (float) $item->tax_total,
                        'benefit' => (float) $item->benefit_total,
                        'overtime' => (float) $item->overtime_amount,
                        'gross' => (float) $item->gross_amount,
                        'net' => (float) $item->net_amount,
                    ],
                    'components' => $item->components()->get()->map->only(['component_code', 'component_name', 'kind', 'amount', 'source'])->all(),
                    'inputs' => $item->snapshot,
                ];

                $checksum = hash('sha256', json_encode($payload));

                HrPayrollSnapshot::query()->updateOrCreate(
                    ['run_id' => $run->getKey(), 'employee_id' => $item->employee_id],
                    [
                        'period_code' => (string) $run->period?->code,
                        'is_immutable' => true,
                        'payload' => $payload,
                        'checksum' => $checksum,
                        'created_by' => $actor->getKey(),
                    ],
                );

                $item->forceFill(['status' => 'LOCKED'])->save();

                HrPayslip::query()->updateOrCreate(
                    ['run_id' => $run->getKey(), 'employee_id' => $item->employee_id],
                    [
                        'code' => 'SLIP-'.$run->code.'-'.$item->employee_id,
                        'period_id' => $run->period_id,
                        'period_code' => (string) $run->period?->code,
                        'number' => 'SLIP/'.(string) $run->period?->code.'/'.$item->employee_id,
                        'gross_amount' => $item->gross_amount,
                        'deduction_total' => $item->deduction_total,
                        'tax_total' => $item->tax_total,
                        'net_amount' => $item->net_amount,
                        'currency' => $item->currency,
                        'issued_at' => now(),
                        'status' => 'GENERATED',
                        'checksum' => $checksum,
                        'snapshot' => $payload,
                    ],
                );
            }

            $run->forceFill([
                'status' => 'FINALIZED',
                'finalized_by' => $actor->getKey(),
                'finalized_at' => now(),
            ])->save();

            $run->period?->forceFill(['status' => 'FINALIZED'])->save();

            $this->recordApproval($run, 'FINALIZE', 'FINALIZER', $actor, 'APPROVED', null);

            $this->events->emit('PAYROLL', 'payroll.finalized', [
                'actor' => $actor,
                'subject_type' => HrPayrollRun::class,
                'subject_id' => $run->getKey(),
                'title' => 'Penggajian '.$run->code.' difinalkan',
                'payload' => ['employees' => $run->items()->count(), 'net_total' => (float) $run->net_total],
            ]);

            return $run->refresh();
        });
    }

    /**
     * Posting ke Finance: jurnal ringkas per departemen dan komponen.
     */
    public function postRunToFinance(HrPayrollRun $run, User $actor): array
    {
        if ($run->status !== 'FINALIZED') {
            throw HrdValidationException::forField('status', 'Penggajian harus difinalkan sebelum diposting.', 'payroll.not_finalized');
        }

        $key = 'payroll-posting:'.$run->getKey();
        $existing = HrPayrollPosting::query()->where('idempotency_key', $key)->first();

        if ($existing !== null && $existing->status === 'POSTED') {
            return ['posting_id' => $existing->getKey(), 'status' => 'POSTED', 'already_posted' => true];
        }

        $lines = $this->journalLines($run);

        $posting = $existing ?? HrPayrollPosting::query()->create([
            'run_id' => $run->getKey(),
            'journal_reference' => 'JRN-PR-'.$run->code,
            'posting_type' => 'JOURNAL',
            'status' => 'PENDING',
            'amount' => (float) $run->net_total,
            'lines' => $lines,
            'idempotency_key' => $key,
        ]);

        $reference = null;

        try {
            $finance = app(FinancePostingProviderInterface::class);
            $result = $finance->postJournal([
                'reference' => $posting->journal_reference,
                'date' => now()->toDateString(),
                'description' => 'Penggajian '.$run->code,
                'lines' => $lines,
            ], $actor);
            $reference = $result['reference'] ?? null;
        } catch (Throwable $exception) {
            // Modul keuangan mungkin belum terpasang: jurnal tetap tersimpan dan
            // dapat dikirim ulang penjadwal tanpa menggandakan.
            report($exception);
        }

        $posting->forceFill([
            'status' => $reference !== null ? 'POSTED' : 'PENDING',
            'finance_reference' => $reference,
            'posted_at' => $reference !== null ? now() : null,
            'attempts' => (int) $posting->attempts + 1,
            'last_error' => $reference !== null ? null : 'Finance belum tersedia; jurnal menunggu pengiriman.',
        ])->save();

        if ($reference !== null) {
            $run->forceFill(['status' => 'POSTED', 'posted_at' => now(), 'journal_reference' => $posting->journal_reference])->save();
            $run->period?->forceFill(['status' => 'POSTED'])->save();
        }

        $this->events->emit('PAYROLL', 'payroll.posted', [
            'actor' => $actor,
            'subject_type' => HrPayrollRun::class,
            'subject_id' => $run->getKey(),
            'title' => 'Jurnal penggajian '.$run->code.' '.($reference !== null ? 'terkirim' : 'menunggu Finance'),
            'payload' => ['lines' => count($lines), 'reference' => $reference],
        ]);

        return ['posting_id' => $posting->getKey(), 'status' => $posting->status, 'lines' => $lines, 'reference' => $reference];
    }

    /**
     * Adaptor kontrak: memposting penggajian berdasarkan id.
     *
     * @return array<string, mixed>
     */
    public function postToFinance(int $runId, int $actorUserId): array
    {
        return $this->postRunToFinance(
            HrPayrollRun::query()->findOrFail($runId),
            User::query()->findOrFail($actorUserId),
        );
    }

    // ------------------------------------------------------------------ penyesuaian

    /** @param array<string, mixed> $attributes */
    public function adjust(HrEmployee $employee, array $attributes, User $actor): \App\Modules\Hrd\Payroll\Models\HrPayrollAdjustment
    {
        $amount = (float) ($attributes['amount'] ?? 0);

        if ($amount <= 0) {
            throw HrdValidationException::forField('amount', 'Nilai penyesuaian harus lebih dari nol.', 'payroll.adjustment_amount');
        }

        $key = $attributes['idempotency_key'] ?? null;

        if ($key !== null) {
            $existing = \App\Modules\Hrd\Payroll\Models\HrPayrollAdjustment::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $adjustment = \App\Modules\Hrd\Payroll\Models\HrPayrollAdjustment::query()->create([
            'code' => sprintf('ADJ-%s-%04d', now()->format('Y'), \App\Modules\Hrd\Payroll\Models\HrPayrollAdjustment::query()->whereYear('created_at', now()->year)->count() + 1),
            'employee_id' => $employee->getKey(),
            'period_id' => $attributes['period_id'] ?? null,
            'component_code' => strtoupper((string) ($attributes['component_code'] ?? 'CORRECTION')),
            'adjustment_type' => strtoupper((string) ($attributes['adjustment_type'] ?? 'CORRECTION')),
            'amount' => $amount,
            'reason' => $attributes['reason'] ?? 'Penyesuaian',
            'status' => 'APPROVED',
            'approved_by' => $attributes['approved_by'] ?? $actor->getKey(),
            'approved_at' => now(),
            'idempotency_key' => $key,
        ]);

        $this->events->emit('PAYROLL', 'payroll.adjustment', [
            'actor' => $actor,
            'employee_id' => $employee->getKey(),
            'subject_type' => \App\Modules\Hrd\Payroll\Models\HrPayrollAdjustment::class,
            'subject_id' => $adjustment->getKey(),
            'title' => 'Penyesuaian gaji '.$adjustment->adjustment_type.' '.$amount,
            'payload' => ['component' => $adjustment->component_code, 'period_id' => $adjustment->period_id],
        ]);

        return $adjustment;
    }

    /** Menerapkan penyesuaian yang belum diterapkan pada sebuah proses penggajian. */
    public function applyAdjustments(HrPayrollRun $run, User $actor): int
    {
        $applied = 0;

        $adjustments = \App\Modules\Hrd\Payroll\Models\HrPayrollAdjustment::query()
            ->where('period_id', $run->period_id)
            ->where('status', 'APPROVED')
            ->whereNull('applied_run_id')
            ->get();

        foreach ($adjustments as $adjustment) {
            $item = HrPayrollItem::query()->where('run_id', $run->getKey())->where('employee_id', $adjustment->employee_id)->first();

            if ($item === null) {
                continue;
            }

            $isDeduction = in_array(strtoupper((string) $adjustment->adjustment_type), ['DEDUCTION', 'OVERPAYMENT_RECOVERY'], true);

            HrPayrollItemComponent::query()->create([
                'item_id' => $item->getKey(),
                'component_code' => $adjustment->component_code,
                'component_name' => 'Penyesuaian '.$adjustment->code,
                'kind' => $isDeduction ? 'DEDUCTION' : 'EARNING',
                'amount' => (float) $adjustment->amount,
                'source' => 'ADJUSTMENT',
                'reference' => ['adjustment_id' => $adjustment->getKey(), 'type' => $adjustment->adjustment_type],
            ]);

            if ($isDeduction) {
                $item->forceFill([
                    'deduction_total' => (float) $item->deduction_total + (float) $adjustment->amount,
                    'net_amount' => (float) $item->net_amount - (float) $adjustment->amount,
                ])->save();
            } else {
                $item->forceFill([
                    'earning_total' => (float) $item->earning_total + (float) $adjustment->amount,
                    'gross_amount' => (float) $item->gross_amount + (float) $adjustment->amount,
                    'net_amount' => (float) $item->net_amount + (float) $adjustment->amount,
                ])->save();
            }

            $adjustment->forceFill(['applied_run_id' => $run->getKey(), 'status' => 'APPLIED'])->save();
            $applied++;
        }

        if ($applied > 0) {
            $items = HrPayrollItem::query()->where('run_id', $run->getKey())->get();

            $run->forceFill([
                'gross_total' => $items->sum('gross_amount'),
                'earning_total' => $items->sum('earning_total'),
                'deduction_total' => $items->sum('deduction_total'),
                'net_total' => $items->sum('net_amount'),
            ])->save();
        }

        return $applied;
    }

    // ------------------------------------------------------------------ rekonsiliasi

    /**
     * Rekonsiliasi sebelum pengesahan (§81): jumlah pegawai, total bruto,
     * potongan, pajak, neto, dan selisih terhadap periode sebelumnya.
     *
     * @return array<string, mixed>
     */
    public function reconciliation(HrPayrollRun $run): array
    {
        $previous = HrPayrollRun::query()
            ->whereKeyNot($run->getKey())
            ->whereIn('status', ['FINALIZED', 'POSTED'])
            ->when($run->organization_id !== null, fn ($query) => $query->where('organization_id', $run->organization_id))
            ->orderByDesc('id')
            ->first();

        $current = [
            'employee_count' => (int) $run->employee_count,
            'gross_total' => (float) $run->gross_total,
            'deduction_total' => (float) $run->deduction_total,
            'tax_total' => (float) $run->tax_total,
            'benefit_total' => (float) $run->benefit_total,
            'net_total' => (float) $run->net_total,
            'employer_cost_total' => (float) $run->employer_cost_total,
        ];

        if ($previous === null) {
            return [
                'current' => $current,
                'previous' => null,
                'variance' => [],
                'flagged' => false,
                'note' => 'Belum ada periode final sebelumnya untuk dibandingkan.',
            ];
        }

        $previousValues = [
            'employee_count' => (int) $previous->employee_count,
            'gross_total' => (float) $previous->gross_total,
            'deduction_total' => (float) $previous->deduction_total,
            'tax_total' => (float) $previous->tax_total,
            'benefit_total' => (float) $previous->benefit_total,
            'net_total' => (float) $previous->net_total,
            'employer_cost_total' => (float) $previous->employer_cost_total,
        ];

        $thresholds = [
            'gross_total' => (float) config('hrd_phase2.payroll.variance_gross_percent', 15),
            'net_total' => (float) config('hrd_phase2.payroll.variance_net_percent', 15),
            'employee_count' => (float) config('hrd_phase2.payroll.variance_headcount', 5),
        ];

        $variance = [];
        $flagged = false;

        foreach ($current as $metric => $value) {
            $before = $previousValues[$metric];
            $limit = $thresholds[$metric] ?? null;

            if ($metric === 'employee_count') {
                $delta = $value - $before;
                $percent = null;
                $isFlagged = $limit !== null && abs($delta) > $limit;
            } else {
                $delta = round($value - $before, 2);
                $percent = $before > 0 ? round($delta / $before * 100, 2) : null;
                $isFlagged = $limit !== null && $percent !== null && abs($percent) > $limit;
            }

            $variance[$metric] = [
                'previous' => $before,
                'current' => $value,
                'delta' => $delta,
                'percent' => $percent,
                'threshold' => $limit,
                'flagged' => $isFlagged,
            ];

            $flagged = $flagged || $isFlagged;
        }

        return [
            'current' => $current,
            'previous' => ['run_code' => $previous->code, 'period_code' => $previous->period?->code] + $previousValues,
            'variance' => $variance,
            'flagged' => $flagged,
        ];
    }

    // ------------------------------------------------------------------ kontrak & bantuan

    public function runPeriod(array $payload, ?int $actorUserId = null): array
    {
        $actor = $actorUserId !== null ? User::query()->find($actorUserId) : User::query()->first();

        if ($actor === null) {
            throw HrdValidationException::forField('actor', 'Pelaku perubahan wajib diketahui.', 'payroll.actor_required');
        }

        $period = isset($payload['period_id'])
            ? HrPayrollPeriod::query()->findOrFail($payload['period_id'])
            : $this->openPeriod($payload, $actor);

        $run = $this->createRun($period, $payload, $actor);
        $run = $this->calculate($run, $actor);

        return ['run_id' => $run->getKey(), 'code' => $run->code, 'status' => $run->status, 'employees' => (int) $run->employee_count, 'net_total' => (float) $run->net_total];
    }

    public function finalizeRun(int $runId, int $actorUserId): array
    {
        $run = HrPayrollRun::query()->findOrFail($runId);
        $actor = User::query()->findOrFail($actorUserId);
        $finalized = $this->finalize($run, $actor);

        return ['run_id' => $finalized->getKey(), 'status' => $finalized->status, 'payslips' => $finalized->items()->count()];
    }

    public function netPay(int $employeeId, string $periodCode): ?float
    {
        $item = HrPayrollItem::query()
            ->where('employee_id', $employeeId)
            ->whereHas('run.period', fn ($query) => $query->where('code', $periodCode))
            ->whereHas('run', fn ($query) => $query->whereIn('status', ['FINALIZED', 'POSTED']))
            ->first();

        return $item !== null ? (float) $item->net_amount : null;
    }

    public function payslipPath(int $employeeId, string $periodCode): ?string
    {
        $payslip = HrPayslip::query()
            ->where('employee_id', $employeeId)
            ->where('period_code', $periodCode)
            ->first();

        return $payslip?->pdf_path;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, HrEmployee> */
    private function employeesFor(HrPayrollRun $run): \Illuminate\Database\Eloquent\Collection
    {
        $period = $run->period;
        $start = (string) $period?->start_date?->toDateString();

        return HrEmployee::query()
            ->where('lifecycle_state', 'active')
            ->when($run->organization_id !== null, fn ($query) => $query->where('organization_id', $run->organization_id))
            ->when($run->department_id !== null, fn ($query) => $query->where('department_id', $run->department_id))
            ->when($start !== '', fn ($query) => $query->where(fn ($inner) => $inner->whereNull('hire_date')->orWhere('hire_date', '<=', $start)))
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function taxContextFor(HrEmployee $employee): array
    {
        $employee->loadMissing('person', 'dependents');

        $dependents = method_exists($employee, 'dependents') ? $employee->dependents()->count() : 0;

        return [
            'employee_id' => $employee->getKey(),
            'married' => strtolower((string) ($employee->person?->marital_status ?? '')) === 'married',
            'dependents' => min(3, (int) $dependents),
            'ptkp_base' => 54000000,
            'months' => 12,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function journalLines(HrPayrollRun $run): array
    {
        $lines = [];

        $items = HrPayrollItem::query()->with('components')->where('run_id', $run->getKey())->get();

        foreach ($items as $item) {
            $costCenter = $item->components->first()?->reference['cost_center'] ?? null;

            $lines[] = [
                'account' => '2101-UTANG-GAJI',
                'description' => 'Utang gaji '.$item->employee_name,
                'debit' => 0.0,
                'credit' => (float) $item->net_amount,
                'department_id' => $item->department_id,
                'organization_id' => $item->organization_id,
                'cost_center' => $costCenter,
            ];
        }

        $lines[] = [
            'account' => '5101-BEBAN-GAJI',
            'description' => 'Beban gaji '.$run->code,
            'debit' => (float) $run->gross_total,
            'credit' => 0.0,
            'organization_id' => $run->organization_id,
        ];

        if ((float) $run->tax_total > 0) {
            $lines[] = [
                'account' => '2103-UTANG-PAJAK',
                'description' => 'Utang PPh 21 dan potongan wajib',
                'debit' => 0.0,
                'credit' => (float) $run->tax_total,
                'organization_id' => $run->organization_id,
            ];
        }

        if ((float) $run->deduction_total > 0) {
            $lines[] = [
                'account' => '2104-UTANG-POTONGAN',
                'description' => 'Potongan pegawai',
                'debit' => 0.0,
                'credit' => (float) $run->deduction_total,
                'organization_id' => $run->organization_id,
            ];
        }

        return $lines;
    }

    private function recordApproval(HrPayrollRun $run, string $stage, string $role, User $actor, string $decision, ?string $notes): HrPayrollApproval
    {
        return HrPayrollApproval::query()->create([
            'run_id' => $run->getKey(),
            'stage' => $stage,
            'actor_role' => $role,
            'actor_user_id' => $actor->getKey(),
            'decision' => $decision,
            'decided_at' => now(),
            'notes' => $notes,
        ]);
    }

    /**
     * Pemisahan tugas (§80): penyusun tidak boleh menjadi pemeriksa/pengesah
     * bila kebijakan mengaktifkannya.
     */
    private function assertSeparation(HrPayrollRun $run, User $actor, string $role): void
    {
        if (! (bool) config('hrd_phase2.payroll.separation_of_duties', true)) {
            return;
        }

        $actorId = (int) $actor->getKey();

        $conflict = match ($role) {
            'CHECKER' => (int) $run->prepared_by === $actorId,
            'APPROVER' => (int) $run->prepared_by === $actorId || (int) $run->reviewed_by === $actorId,
            'FINALIZER' => (int) $run->prepared_by === $actorId || (int) $run->approved_by === $actorId,
            default => false,
        };

        if ($conflict) {
            throw HrdValidationException::forField(
                'actor',
                'Pemisahan tugas: penyusun penggajian tidak boleh menjadi '.strtolower($role).' pada proses yang sama.',
                'payroll.separation_of_duties',
            );
        }
    }
}
