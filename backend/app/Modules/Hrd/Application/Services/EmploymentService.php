<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Application\Support\EmployeeNumberGenerator;
use App\Modules\Hrd\Application\Support\HrdSettingsService;
use App\Modules\Hrd\Domain\Contracts\EmploymentProviderInterface;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrContract;
use App\Modules\Hrd\Models\HrContractType;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeAssignment;
use App\Modules\Hrd\Models\HrEmployeeManager;
use App\Modules\Hrd\Models\HrEmploymentRecord;
use App\Modules\Hrd\Models\HrProbation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Masa kerja, percobaan, dan kontrak (§16, §17, §36, §37).
 *
 * Pertanyaan yang harus terjawab dari layanan ini, dan tidak dapat dijawab oleh
 * satu kolom `join_date`:
 *
 *  - berapa total masa kerja Ahmad, termasuk periode pertama yang berakhir 2024?
 *  - kontrak mana yang berlaku pada 1 Maret lalu?
 *  - berapa pegawai yang masa percobaannya berakhir bulan ini?
 *  - berapa nilai "masa kerja" untuk tunjangan?
 */
class EmploymentService implements EmploymentProviderInterface
{
    public function __construct(
        private readonly EmployeeNumberGenerator $numbers,
        private readonly HrdSettingsService $settings,
        private readonly HrdTimelineService $timeline,
        private readonly HrdAuditService $audit,
        private readonly AssignmentService $assignments,
    ) {}

    /* ── Masa kerja ─────────────────────────────────────────────────────── */

    /** @param array<string, mixed> $attributes */
    public function createEmploymentRecord(HrEmployee $employee, array $attributes, User $actor): HrEmploymentRecord
    {
        if (empty($attributes['start_date'])) {
            throw HrdValidationException::forField('start_date', 'Tanggal mulai masa kerja wajib diisi.', 'hr.employment.start_date_required');
        }

        return DB::transaction(function () use ($employee, $attributes, $actor): HrEmploymentRecord {
            $record = HrEmploymentRecord::query()->create([
                'employee_id' => $employee->getKey(),
                'employment_number' => $attributes['employment_number'] ?? $employee->employee_number,
                'employment_type_code' => $attributes['employment_type_code'] ?? $employee->employment_type ?? 'Permanent',
                'worker_type_code' => $attributes['worker_type_code'] ?? $employee->worker_type,
                'start_date' => $attributes['start_date'],
                'end_date' => $attributes['end_date'] ?? null,
                'organization_id' => $attributes['organization_id'] ?? $employee->organization_id,
                'department_id' => $attributes['department_id'] ?? $employee->department_id,
                'position_id' => $attributes['position_id'] ?? $employee->position_id,
                'status' => $attributes['status'] ?? 'active',
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log($actor, 'employment.record_created', $record, null, $record->toArray(), null, null, (int) $employee->getKey());

            return $record;
        });
    }

    public function currentEmployment(int $employeeId): ?array
    {
        return HrEmploymentRecord::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->orderByDesc('start_date')
            ->first()?->toArray();
    }

    public function employmentHistory(int $employeeId): array
    {
        return HrEmploymentRecord::query()
            ->where('employee_id', $employeeId)
            ->orderByDesc('start_date')
            ->get()
            ->toArray();
    }

    /**
     * Total masa kerja dalam bulan, dijumlahkan dari seluruh periode (§17).
     * Periode yang masih berjalan dihitung sampai `$asOf`.
     */
    public function totalServiceMonths(int $employeeId, ?string $asOf = null): int
    {
        $end = $asOf === null ? now() : Carbon::parse($asOf);

        $records = HrEmploymentRecord::query()->where('employee_id', $employeeId)->get();

        if ($records->isEmpty()) {
            $hireDate = HrEmployee::query()->whereKey($employeeId)->value('hire_date');

            return $hireDate === null ? 0 : max(0, (int) Carbon::parse($hireDate)->diffInMonths($end));
        }

        $months = 0;

        foreach ($records as $record) {
            $start = Carbon::parse($record->start_date);
            $stop = $record->end_date === null ? $end : Carbon::parse($record->end_date);

            if ($stop->lessThan($start)) {
                continue;
            }

            $months += (int) $start->diffInMonths($stop);
        }

        return $months;
    }

    /* ── Masa percobaan ─────────────────────────────────────────────────── */

    /** @param array<string, mixed> $attributes */
    public function createProbation(HrEmployee $employee, array $attributes, User $actor): HrProbation
    {
        $start = $attributes['start_date'] ?? $employee->hire_date?->toDateString();

        if ($start === null) {
            throw HrdValidationException::forField('start_date', 'Pegawai belum memiliki tanggal masuk; isi tanggal masuk lebih dulu.', 'hr.probation.no_hire_date');
        }

        $months = (int) ($attributes['months'] ?? 3);
        $end = $attributes['end_date'] ?? Carbon::parse($start)->addMonths($months)->subDay()->toDateString();

        $probation = HrProbation::query()->create([
            'employee_id' => $employee->getKey(),
            'employment_record_id' => $attributes['employment_record_id'] ?? $this->currentEmploymentRecordId($employee),
            'start_date' => $start,
            'end_date' => $end,
            'status' => Carbon::parse($end)->isPast() ? 'active' : 'pending',
            'review_date' => $attributes['review_date'] ?? Carbon::parse($end)->subDays(14)->toDateString(),
            'notes' => $attributes['notes'] ?? null,
        ]);

        $employee->forceFill(['probation_end_date' => $end])->save();

        $this->timeline->record($employee, 'probation.started', 'Masa percobaan dimulai', [
            'category' => 'employment',
            'effective_date' => $start,
            'source_type' => HrProbation::class,
            'source_id' => $probation->getKey(),
            'visibility' => 'employee',
            'actor' => $actor,
            'meta' => ['end_date' => $end],
        ]);

        $this->audit->log($actor, 'probation.created', $probation, null, $probation->toArray(), null, null, (int) $employee->getKey());

        return $probation;
    }

    public function onProbation(int $employeeId, ?string $onDate = null): bool
    {
        $date = $onDate ?? now()->toDateString();

        return HrProbation::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'active', 'extended'])
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();
    }

    /** Masa percobaan yang akan berakhir (§47, §70). */
    public function probationsEnding(int $days = 30, ?int $organizationId = null): Collection
    {
        return HrProbation::query()
            ->with(['employee.person:id,full_name', 'employee:id,employee_number,person_id,organization_id'])
            ->whereIn('status', ['pending', 'active', 'extended'])
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->orderBy('end_date')
            ->get();
    }

    /* ── Kontrak ────────────────────────────────────────────────────────── */

    /** @param array<string, mixed> $attributes */
    public function createContract(HrEmployee $employee, array $attributes, User $actor): HrContract
    {
        $type = HrContractType::query()->find($attributes['contract_type_id'] ?? null);

        if ($type === null) {
            throw HrdValidationException::forField('contract_type_id', 'Jenis kontrak wajib dipilih.', 'hr.contract.type_required');
        }

        if ($type->is_fixed_term && empty($attributes['start_date'])) {
            throw HrdValidationException::forField('start_date', 'Kontrak berjangka wajib memiliki tanggal mulai.', 'hr.contract.start_required');
        }

        if ($type->is_fixed_term && empty($attributes['end_date'])) {
            throw HrdValidationException::forField('end_date', 'Kontrak berjangka wajib memiliki tanggal berakhir (§57).', 'hr.contract.end_date_required');
        }

        if (! empty($attributes['start_date']) && ! empty($attributes['end_date'])
            && strtotime((string) $attributes['end_date']) < strtotime((string) $attributes['start_date'])) {
            throw HrdValidationException::forField('end_date', 'Tanggal berakhir kontrak mendahului tanggal mulai.', 'hr.contract.invalid_period');
        }

        return DB::transaction(function () use ($employee, $attributes, $actor, $type): HrContract {
            $contract = HrContract::query()->create([
                'contract_number' => $attributes['contract_number'] ?? $this->numbers->next('contract', [
                    'organization_id' => $employee->organization_id,
                ]),
                'employee_id' => $employee->getKey(),
                'contract_type_id' => $type->getKey(),
                'employment_record_id' => $attributes['employment_record_id'] ?? $this->currentEmploymentRecordId($employee),
                'position_id' => $attributes['position_id'] ?? $employee->position_id,
                'start_date' => $attributes['start_date'],
                'end_date' => $attributes['end_date'] ?? null,
                'renewal_date' => $attributes['renewal_date'] ?? null,
                'signed_date' => $attributes['signed_date'] ?? null,
                'status' => $attributes['status'] ?? (empty($attributes['signed_date']) ? 'draft' : 'active'),
                'is_signed' => ! empty($attributes['signed_date']),
                'document_id' => $attributes['document_id'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            $this->timeline->record($employee, 'contract.created', 'Kontrak '.$type->name.' '.$contract->contract_number, [
                'category' => 'employment',
                'effective_date' => $contract->start_date?->toDateString(),
                'source_type' => HrContract::class,
                'source_id' => $contract->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
                'meta' => ['end_date' => $contract->end_date?->toDateString(), 'status' => $contract->status],
            ]);

            $this->audit->log($actor, 'contract.created', $contract, null, $contract->toArray(), null, null, (int) $employee->getKey());

            return $contract->refresh();
        });
    }

    public function activeContract(int $employeeId, ?string $onDate = null): ?array
    {
        $date = $onDate ?? now()->toDateString();

        return HrContract::query()
            ->with('contractType:id,name,code')
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['active', 'expiring'])
            ->where('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
            ->orderByDesc('start_date')
            ->first()?->toArray();
    }

    public function expiringContracts(int $days, ?int $organizationId = null): array
    {
        return HrContract::query()
            ->with(['employee.person:id,full_name', 'contractType:id,name'])
            ->whereIn('status', ['active', 'expiring'])
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->orderBy('end_date')
            ->get()
            ->toArray();
    }

    /**
     * Hitung ulang status kontrak & tandai yang akan berakhir (§70 pengerjaan harian).
     *
     * @return array{expiring: int, expired: int}
     */
    public function refreshContractStatuses(): array
    {
        $warningWindows = $this->settings->reminderWindows('contract_days');
        $largestWindow = $warningWindows === [] ? 30 : max($warningWindows);

        $expired = HrContract::query()
            ->whereIn('status', ['active', 'expiring'])
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now()->toDateString())
            ->update(['status' => 'expired']);

        $expiring = HrContract::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays($largestWindow)->toDateString()])
            ->update(['status' => 'expiring']);

        return ['expiring' => $expiring, 'expired' => $expired];
    }

    /* ── Penugasan (delegasi ke AssignmentService) ──────────────────────── */

    public function assignmentsOn(int $employeeId, ?string $onDate = null): array
    {
        return $this->assignments->assignmentsOn($employeeId, $onDate)->toArray();
    }

    /** Siapa menempati sebuah posisi pada tanggal tertentu (§19). */
    public function positionHoldersOn(int $positionId, string $onDate): Collection
    {
        return HrEmployeeAssignment::query()
            ->with(['employee.person:id,full_name', 'employee:id,employee_number,person_id'])
            ->where('position_id', $positionId)
            ->effectiveOn($onDate)
            ->whereIn('status', ['active', 'ended'])
            ->get();
    }

    /** Siapa kepala departemen pada tanggal tertentu (§19). */
    public function departmentHeadOn(int $departmentId, string $onDate): ?array
    {
        $department = \App\Modules\Hrd\Models\HrDepartment::query()->find($departmentId);

        if ($department === null) {
            return null;
        }

        $headAssignment = HrEmployeeAssignment::query()
            ->with(['employee.person:id,full_name'])
            ->where('department_id', $departmentId)
            ->where('is_primary', true)
            ->whereHas('position', fn ($q) => $q->where('title', 'like', '%kepala%')->orWhere('title', 'like', '%head%'))
            ->effectiveOn($onDate)
            ->first();

        if ($headAssignment?->employee !== null) {
            return [
                'employee_id' => (int) $headAssignment->employee_id,
                'name' => $headAssignment->employee->displayName(),
                'position' => $headAssignment->position?->title,
                'start_date' => $headAssignment->start_date?->toDateString(),
            ];
        }

        $head = $department->head;

        return $head === null ? null : [
            'employee_id' => (int) $head->getKey(),
            'name' => $head->displayName(),
            'position' => $head->position?->title,
            'start_date' => null,
        ];
    }

    /** Rantai atasan sampai akar (maksimum delapan tingkat). */
    public function managementChain(int $employeeId, ?string $onDate = null): array
    {
        $chain = [];
        $currentId = $employeeId;
        $visited = [];

        for ($depth = 0; $depth < 8; $depth++) {
            $relation = HrEmployeeManager::query()
                ->with(['manager.person:id,full_name'])
                ->where('employee_id', $currentId)
                ->where('status', 'active')
                ->effectiveOn($onDate ?? now()->toDateString())
                ->orderBy('is_primary', 'desc')
                ->first();

            if ($relation?->manager === null || in_array((int) $relation->manager_employee_id, $visited, true)) {
                break;
            }

            $visited[] = (int) $relation->manager_employee_id;

            $chain[] = [
                'employee_id' => (int) $relation->manager_employee_id,
                'name' => $relation->manager->displayName(),
                'relationship_type' => $relation->relationship_type,
            ];

            $currentId = (int) $relation->manager_employee_id;
        }

        return $chain;
    }

    private function currentEmploymentRecordId(HrEmployee $employee): ?int
    {
        return HrEmploymentRecord::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', 'active')
            ->orderByDesc('start_date')
            ->value('id');
    }
}
