<?php

namespace App\Modules\Hrd\Payroll\Services;

use App\Models\User;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Payroll\Models\HrEmployeeSalaryAssignment;
use App\Modules\Hrd\Payroll\Models\HrEmployeeSalaryComponent;
use App\Modules\Hrd\Payroll\Models\HrSalaryComponent;
use App\Modules\Hrd\Payroll\Models\HrSalaryStructure;
use App\Modules\Hrd\Payroll\Models\HrSalaryStructureLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Struktur gaji yang dapat dikonfigurasi (§36–§37).
 *
 * Tidak ada satu pun tempat di modul ini yang menulis "gaji = pokok + transport".
 * Komponen, strukturnya, dan siapa yang mendapatkannya adalah data; karena itu
 * sekolah dapat menambah tunjangan baru tanpa penerapan ulang perangkat lunak.
 */
class SalaryStructureService
{
    /** @param array<string, mixed> $attributes */
    public function createComponent(array $attributes, User $actor): HrSalaryComponent
    {
        $kind = strtoupper((string) ($attributes['kind'] ?? 'EARNING'));

        if (! in_array($kind, ['EARNING', 'DEDUCTION', 'TAX', 'BENEFIT', 'EMPLOYER_CONTRIBUTION'], true)) {
            throw HrdValidationException::forField('kind', 'Jenis komponen tidak dikenal.', 'payroll.component_kind_invalid');
        }

        $defaults = config('hrd_phase2.payroll')[strtolower($kind)] ?? [];

        return HrSalaryComponent::query()->updateOrCreate(
            ['code' => strtoupper((string) $attributes['code'])],
            [
                'name' => $attributes['name'] ?? $attributes['code'],
                'kind' => $kind,
                'category' => $attributes['category'] ?? null,
                'is_taxable' => (bool) ($attributes['is_taxable'] ?? ($defaults['taxable'] ?? true)),
                'is_prorated' => (bool) ($attributes['is_prorated'] ?? ($defaults['prorate'] ?? false)),
                'is_fixed' => (bool) ($attributes['is_fixed'] ?? true),
                'is_statutory' => (bool) ($attributes['is_statutory'] ?? false),
                'calculation_type' => strtoupper((string) ($attributes['calculation_type'] ?? 'FIXED')),
                'formula' => $attributes['formula'] ?? null,
                'percentage_of' => $attributes['percentage_of'] ?? null,
                'default_amount' => $attributes['default_amount'] ?? null,
                'unit_label' => $attributes['unit_label'] ?? null,
                'gl_account_code' => $attributes['gl_account_code'] ?? null,
                'order_index' => (int) ($attributes['order_index'] ?? 0),
            ],
        );
    }

    /** @param array<string, mixed> $attributes @param list<array<string, mixed>> $lines */
    public function createStructure(array $attributes, array $lines, User $actor): HrSalaryStructure
    {
        return DB::transaction(function () use ($attributes, $lines, $actor) {
            $structure = HrSalaryStructure::query()->create([
                'code' => strtoupper((string) ($attributes['code'] ?? 'STRUCT-'.now()->format('YmdHis'))),
                'name' => $attributes['name'] ?? 'Struktur Gaji',
                'organization_id' => $attributes['organization_id'] ?? null,
                'job_grade_code' => $attributes['job_grade_code'] ?? null,
                'position_id' => $attributes['position_id'] ?? null,
                'employment_type' => $attributes['employment_type'] ?? null,
                'effective_from' => $attributes['effective_from'] ?? now()->startOfYear()->toDateString(),
                'effective_to' => $attributes['effective_to'] ?? null,
                'currency' => $attributes['currency'] ?? 'IDR',
                'base_amount' => $attributes['base_amount'] ?? null,
                'is_default' => (bool) ($attributes['is_default'] ?? false),
                'notes' => $attributes['notes'] ?? null,
            ]);

            if ($structure->is_default) {
                HrSalaryStructure::query()->whereKeyNot($structure->getKey())->update(['is_default' => false]);
            }

            foreach ($lines as $line) {
                $component = isset($line['component_id'])
                    ? HrSalaryComponent::query()->find($line['component_id'])
                    : HrSalaryComponent::query()->where('code', strtoupper((string) ($line['component_code'] ?? '')))->first();

                if ($component === null) {
                    throw HrdValidationException::forField('lines', 'Komponen gaji tidak ditemukan: '.($line['component_code'] ?? ''), 'payroll.component_missing');
                }

                HrSalaryStructureLine::query()->updateOrCreate(
                    ['structure_id' => $structure->getKey(), 'component_id' => $component->getKey()],
                    [
                        'amount' => $line['amount'] ?? $component->default_amount,
                        'percentage' => $line['percentage'] ?? null,
                        'formula' => $line['formula'] ?? null,
                        'is_optional' => (bool) ($line['is_optional'] ?? false),
                        'order_index' => (int) ($line['order_index'] ?? $component->order_index),
                    ],
                );
            }

            return $structure->refresh();
        });
    }

    /**
     * Menetapkan struktur gaji kepada pegawai. Penetapan lama ditutup, tidak
     * ditimpa — riwayat gaji harus dapat dibaca ulang kapan pun (§39).
     *
     * @param array<string, mixed> $attributes
     */
    public function assign(HrEmployee $employee, array $attributes, User $actor): HrEmployeeSalaryAssignment
    {
        $structure = isset($attributes['structure_id'])
            ? HrSalaryStructure::query()->find($attributes['structure_id'])
            : $this->defaultStructureFor($employee);

        if ($structure === null) {
            throw HrdValidationException::forField('structure_id', 'Struktur gaji tidak ditemukan.', 'payroll.structure_missing');
        }

        $baseAmount = (float) ($attributes['base_amount'] ?? $structure->base_amount ?? 0);
        $effectiveFrom = $attributes['effective_from'] ?? now()->startOfMonth()->toDateString();

        if ($baseAmount <= 0) {
            throw HrdValidationException::forField('base_amount', 'Gaji pokok harus lebih dari nol.', 'payroll.base_invalid');
        }

        return DB::transaction(function () use ($employee, $structure, $attributes, $actor, $baseAmount, $effectiveFrom) {
            $previous = HrEmployeeSalaryAssignment::query()
                ->where('employee_id', $employee->getKey())
                ->where('status', 'ACTIVE')
                ->orderByDesc('effective_from')
                ->first();

            if ($previous !== null && Carbon::parse($previous->effective_from)->greaterThanOrEqualTo($effectiveFrom)) {
                throw HrdValidationException::forField('effective_from', 'Tanggal berlaku harus setelah penetapan gaji sebelumnya.', 'payroll.effective_invalid');
            }

            if ($previous !== null) {
                $previous->forceFill([
                    'status' => 'ENDED',
                    'effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString(),
                ])->save();
            }

            $assignment = HrEmployeeSalaryAssignment::query()->create([
                'employee_id' => $employee->getKey(),
                'structure_id' => $structure->getKey(),
                'effective_from' => $effectiveFrom,
                'base_amount' => $baseAmount,
                'currency' => $structure->currency ?? 'IDR',
                'status' => 'ACTIVE',
                'reason' => $attributes['reason'] ?? null,
                'approved_by' => $attributes['approved_by'] ?? $actor->getKey(),
                'approved_at' => now(),
                'previous_assignment_id' => $previous?->getKey(),
                'workflow_instance_id' => $attributes['workflow_instance_id'] ?? null,
            ]);

            // Seluruh baris "pendapatan wajib" dari struktur menjadi komponen
            // pegawai, sedangkan yang opsional tetap dipilih manusia.
            foreach ($structure->lines as $line) {
                $component = $line->component;

                if ($component === null || $line->is_optional) {
                    continue;
                }

                $this->setComponent($employee, $component->code, [
                    'amount' => $line->amount ?? $component->default_amount,
                    'percentage' => $line->percentage,
                    'effective_from' => $effectiveFrom,
                    'source' => 'STRUCTURE',
                ], $actor);
            }

            return $assignment->refresh();
        });
    }

    /**
     * Menambah/mengubah komponen gaji pegawai di luar struktur (tunjangan
     * khusus, potongan, iuran benefit).
     *
     * @param array<string, mixed> $attributes
     */
    public function setComponent(HrEmployee $employee, string $componentCode, array $attributes, User $actor): HrEmployeeSalaryComponent
    {
        $component = HrSalaryComponent::query()->where('code', strtoupper($componentCode))->first();

        if ($component === null) {
            throw HrdValidationException::forField('component_code', "Komponen gaji \"{$componentCode}\" tidak ditemukan.", 'payroll.component_missing');
        }

        return HrEmployeeSalaryComponent::query()->updateOrCreate(
            [
                'employee_id' => $employee->getKey(),
                'component_id' => $component->getKey(),
                'effective_from' => $attributes['effective_from'] ?? now()->startOfMonth()->toDateString(),
            ],
            [
                'amount' => $attributes['amount'] ?? null,
                'percentage' => $attributes['percentage'] ?? null,
                'effective_to' => $attributes['effective_to'] ?? null,
                'source' => strtoupper((string) ($attributes['source'] ?? 'MANUAL')),
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'notes' => $attributes['notes'] ?? null,
            ],
        );
    }

    /**
     * Komponen yang berlaku bagi pegawai pada satu periode.
     *
     * @return array{assignment: ?HrEmployeeSalaryAssignment, base_amount: float, earning: array<string, float>, deduction: array<string, float>, benefit: array<string, float>, employer: array<string, float>}
     */
    public function componentsFor(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $assignment = HrEmployeeSalaryAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $periodEnd)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $periodStart))
            ->orderByDesc('effective_from')
            ->first();

        $rows = HrEmployeeSalaryComponent::query()
            ->with('component')
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->where('effective_from', '<=', $periodEnd)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $periodStart))
            ->get();

        $buckets = ['earning' => [], 'deduction' => [], 'benefit' => [], 'employer' => [], 'tax' => []];
        $baseAmount = (float) ($assignment?->base_amount ?? 0);

        foreach ($rows as $row) {
            $component = $row->component;

            if ($component === null) {
                continue;
            }

            $amount = $row->amount !== null
                ? (float) $row->amount
                : ($row->percentage !== null ? round($baseAmount * (float) $row->percentage / 100, 2) : (float) ($component->default_amount ?? 0));

            $bucket = match (strtoupper((string) $component->kind)) {
                'DEDUCTION' => 'deduction',
                'BENEFIT' => 'benefit',
                'EMPLOYER_CONTRIBUTION' => 'employer',
                'TAX' => 'tax',
                default => 'earning',
            };

            $buckets[$bucket][$component->code] = $amount;
        }

        return array_merge($buckets, ['assignment' => $assignment, 'base_amount' => $baseAmount]);
    }

    public function defaultStructureFor(HrEmployee $employee): ?HrSalaryStructure
    {
        return HrSalaryStructure::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $employee->organization_id))
            ->where(fn ($query) => $query->whereNull('employment_type')->orWhere('employment_type', $employee->employment_type))
            ->orderByDesc('is_default')
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @return list<array<string, mixed>> */
    public function employeeAssignments(HrEmployee $employee): array
    {
        return HrEmployeeSalaryAssignment::query()
            ->with('structure')
            ->where('employee_id', $employee->getKey())
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (HrEmployeeSalaryAssignment $assignment) => [
                'structure' => $assignment->structure?->name,
                'base_amount' => (float) $assignment->base_amount,
                'currency' => $assignment->currency,
                'effective_from' => $assignment->effective_from?->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'status' => $assignment->status,
            ])
            ->all();
    }
}
