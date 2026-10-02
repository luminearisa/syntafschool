<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Domain\Enums\AssignmentType;
use App\Modules\Hrd\Domain\Enums\ManagerRelationshipType;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Domain\StateMachines\EmployeeLifecycleStateMachine;
use App\Modules\Hrd\Models\HrDepartment;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeAssignment;
use App\Modules\Hrd\Models\HrEmployeeManager;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Models\HrTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penugasan pegawai & hubungan atasan (§19–§21).
 *
 * Tiga aturan yang ditegakkan di sini — dan tidak dapat dilanggar dari luar:
 *
 *  1. **Satu penugasan utama aktif.** Yang baru dibuat sebelum/menyertai yang lama
 *     ditutup, semuanya di dalam satu transaksi basis data.
 *  2. **Periode tidak tumpang tindih** untuk posisi yang sama pada pegawai yang
 *     sama; memasukkan penugasan bertanggal akan menutup/memangkas yang bersinggungan.
 *  3. **Atasan tidak boleh melingkar.** Rantai atasan ditelusuri sebelum disimpan.
 *
 * Setiap perubahan penugasan memperbarui *ringkasan* pada pegawai
 * (`organization_id`, `department_id`, `position_id`, `location_id`,
 * `manager_employee_id`) dan menghitung ulang keterisian posisi — sehingga tidak
 * ada dua sumber kebenaran yang dapat berbeda.
 */
class AssignmentService
{
    public function __construct(
        private readonly PositionService $positions,
        private readonly HrdTimelineService $timeline,
        private readonly HrdAuditService $audit,
        private readonly EmployeeLifecycleStateMachine $lifecycle,
    ) {}

    /**
     * Buat penugasan baru (§20).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function assign(HrEmployee $employee, array $attributes, User $actor, ?HrTransaction $transaction = null): HrEmployeeAssignment
    {
        return DB::transaction(function () use ($employee, $attributes, $actor, $transaction): HrEmployeeAssignment {
            $employee = HrEmployee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->lifecycle->allowsAssignment($employee->lifecycle_state)) {
                throw HrdValidationException::forField(
                    'employee_id',
                    "Pegawai berstatus \"{$this->lifecycle->label($employee->lifecycle_state)}\" tidak dapat menerima penugasan baru.",
                    'hr.assignment.lifecycle_blocked',
                );
            }

            $type = AssignmentType::tryFrom((string) ($attributes['assignment_type'] ?? AssignmentType::PRIMARY->value))
                ?? AssignmentType::PRIMARY;

            $position = $this->resolvePosition($attributes['position_id'] ?? null);
            $startDate = (string) ($attributes['start_date'] ?? now()->toDateString());
            $endDate = $attributes['end_date'] ?? null;

            if ($endDate !== null && strtotime((string) $endDate) < strtotime($startDate)) {
                throw HrdValidationException::forField('end_date', 'Tanggal berakhir tidak boleh mendahului tanggal mulai.', 'hr.assignment.invalid_period');
            }

            if ($type->requiresEndDate() && $endDate === null) {
                throw HrdValidationException::forField('end_date', 'Penugasan sementara/plt/proyek wajib memiliki tanggal berakhir.', 'hr.assignment.end_date_required');
            }

            if ($position !== null && $type->isPrimary()) {
                $this->assertHeadcountAvailable($position, $employee, $startDate);
            }

            $isPrimary = (bool) ($attributes['is_primary'] ?? $type->isPrimary());

            if ($isPrimary) {
                $this->closePrimaryAssignments($employee, $startDate, $actor, $transaction, 'Digantikan penugasan utama baru');
            }

            $organizationId = $attributes['organization_id'] ?? $position?->organization_id ?? $employee->organization_id;
            $departmentId = $attributes['department_id'] ?? $position?->department_id ?? $employee->department_id;
            $locationId = $attributes['location_id'] ?? $position?->location_id ?? $employee->location_id;

            if ($departmentId !== null && $organizationId !== null) {
                $departmentOrg = HrDepartment::query()->whereKey($departmentId)->value('organization_id');

                if ($departmentOrg !== null && (int) $departmentOrg !== (int) $organizationId) {
                    throw HrdValidationException::forField(
                        'department_id',
                        'Departemen yang dipilih berada pada unit organisasi yang berbeda.',
                        'hr.assignment.department_unit_mismatch',
                    );
                }
            }

            $assignment = HrEmployeeAssignment::query()->create([
                'employee_id' => $employee->getKey(),
                'position_id' => $position?->getKey(),
                'organization_id' => $organizationId,
                'department_id' => $departmentId,
                'location_id' => $locationId,
                'manager_employee_id' => $attributes['manager_employee_id'] ?? $employee->currentManagerId(),
                'assignment_type' => $type->value,
                'is_primary' => $isPrimary,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'percentage' => $attributes['percentage'] ?? null,
                'status' => 'active',
                'transaction_id' => $transaction?->getKey(),
                'reference_number' => $attributes['reference_number'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            if ($isPrimary) {
                $this->syncEmployeeSummary($employee, $assignment, $actor);
            }

            if ($position !== null) {
                $this->positions->recalculate($position);
            }

            $this->timeline->record($employee, 'assignment.created', 'Penugasan: '.$this->describe($assignment), [
                'category' => 'assignment',
                'effective_date' => $assignment->start_date?->toDateString(),
                'source_type' => HrEmployeeAssignment::class,
                'source_id' => $assignment->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
                'meta' => ['assignment_type' => $type->value, 'position_id' => $assignment->position_id],
            ]);

            $this->audit->log($actor, 'assignment.created', $assignment, null, $assignment->toArray(), null, $transaction?->getKey(), (int) $employee->getKey());

            return $assignment->refresh();
        });
    }

    /** Tutup penugasan (§19: penutupan, bukan penghapusan). */
    public function end(HrEmployeeAssignment $assignment, ?string $endDate, User $actor, ?string $reason = null, ?HrTransaction $transaction = null): HrEmployeeAssignment
    {
        return DB::transaction(function () use ($assignment, $endDate, $actor, $reason, $transaction): HrEmployeeAssignment {
            $assignment = HrEmployeeAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();
            $before = $assignment->toArray();

            $date = $endDate ?? now()->toDateString();

            if (strtotime($date) < strtotime($assignment->start_date->toDateString())) {
                throw HrdValidationException::forField('end_date', 'Tanggal berakhir mendahului tanggal mulai penugasan.', 'hr.assignment.invalid_period');
            }

            $assignment->forceFill([
                'end_date' => $date,
                'status' => 'ended',
                'updated_by' => $actor->getKey(),
                'version' => (int) $assignment->version + 1,
                'notes' => $reason === null ? $assignment->notes : trim((string) $assignment->notes.' | '.$reason, ' |'),
            ])->save();

            $employee = $assignment->employee;

            if ($employee !== null && $assignment->is_primary) {
                $replacement = $this->currentPrimary($employee);

                if ($replacement === null) {
                    $employee->forceFill(['is_active' => false])->save();
                } else {
                    $this->syncEmployeeSummary($employee, $replacement, $actor);
                }
            }

            if ($assignment->position_id !== null) {
                $position = HrPosition::query()->find($assignment->position_id);

                if ($position !== null) {
                    $this->positions->recalculate($position);
                }
            }

            $this->timeline->record($assignment->employee_id, 'assignment.ended', 'Penugasan berakhir: '.$this->describe($assignment), [
                'category' => 'assignment',
                'effective_date' => $date,
                'source_type' => HrEmployeeAssignment::class,
                'source_id' => $assignment->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
            ]);

            $this->audit->log($actor, 'assignment.ended', $assignment, $before, $assignment->toArray(), $reason, $transaction?->getKey(), (int) $assignment->employee_id);

            return $assignment->refresh();
        });
    }

    /**
     * Tetapkan atasan (§21) — mendukung atasan langsung, fungsional, dan plt.
     */
    public function setManager(HrEmployee $employee, ?HrEmployee $manager, array $options, User $actor, ?HrTransaction $transaction = null): ?HrEmployeeManager
    {
        return DB::transaction(function () use ($employee, $manager, $options, $actor, $transaction): ?HrEmployeeManager {
            $relationship = ManagerRelationshipType::tryFrom((string) ($options['relationship_type'] ?? ManagerRelationshipType::DIRECT->value))
                ?? ManagerRelationshipType::DIRECT;

            $startDate = (string) ($options['start_date'] ?? now()->toDateString());
            $endDate = $options['end_date'] ?? null;

            $this->closeManagerRelations($employee, $relationship, $startDate, $actor, $transaction);

            if ($manager === null) {
                // Atasan dihapus: kosongkan ringkasan bila tidak ada hubungan lain.
                $this->refreshManagerSummary($employee, $actor);

                return null;
            }

            if ((int) $manager->getKey() === (int) $employee->getKey()) {
                throw HrdValidationException::forField('manager_employee_id', 'Pegawai tidak dapat menjadi atasan dirinya sendiri.', 'hr.manager.self');
            }

            $this->assertNoManagerCycle($employee, $manager);

            $relation = HrEmployeeManager::query()->create([
                'employee_id' => $employee->getKey(),
                'manager_employee_id' => $manager->getKey(),
                'relationship_type' => $relationship->value,
                'organization_id' => $options['organization_id'] ?? $employee->organization_id,
                'department_id' => $options['department_id'] ?? $employee->department_id,
                'position_id' => $employee->position_id,
                'is_primary' => (bool) ($options['is_primary'] ?? $relationship->priority() === 1),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'active',
                'transaction_id' => $transaction?->getKey(),
                'notes' => $options['notes'] ?? null,
            ]);

            $this->refreshManagerSummary($employee, $actor);

            $this->timeline->record($employee, 'manager.changed', 'Atasan '.$relationship->label().': '.$manager->displayName(), [
                'category' => 'assignment',
                'effective_date' => $startDate,
                'source_type' => HrEmployeeManager::class,
                'source_id' => $relation->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
            ]);

            $this->audit->log($actor, 'manager.changed', $relation, null, $relation->toArray(), null, $transaction?->getKey(), (int) $employee->getKey());

            return $relation;
        });
    }

    /** Penugasan yang berlaku pada satu tanggal (§19). */
    public function assignmentsOn(HrEmployee|int $employee, ?string $onDate = null): Collection
    {
        $employeeId = $employee instanceof HrEmployee ? (int) $employee->getKey() : $employee;

        return HrEmployeeAssignment::query()
            ->with(['position:id,code,title', 'organization:id,name,code', 'department:id,name', 'location:id,name', 'manager:id,employee_number,person_id', 'manager.person:id,full_name'])
            ->where('employee_id', $employeeId)
            // Penugasan yang sudah berakhir tetap ikut: pertanyaan §19 adalah
            // "siapa posisinya pada tanggal tertentu", dan jawabannya justru
            // sering berada pada penugasan yang kini telah ditutup.
            ->whereIn('status', ['active', 'ended'])
            ->effectiveOn($onDate ?? now()->toDateString())
            ->orderByDesc('is_primary')
            ->get();
    }

    public function currentPrimary(HrEmployee|int $employee, ?string $onDate = null): ?HrEmployeeAssignment
    {
        $employeeId = $employee instanceof HrEmployee ? (int) $employee->getKey() : $employee;

        return HrEmployeeAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('is_primary', true)
            ->where('status', 'active')
            ->effectiveOn($onDate ?? now()->toDateString())
            ->orderByDesc('start_date')
            ->first();
    }

    /** Penugasan aktif seorang pegawai beserta seluruh riwayatnya. */
    public function history(HrEmployee|int $employee): array
    {
        $employeeId = $employee instanceof HrEmployee ? (int) $employee->getKey() : $employee;

        $assignments = HrEmployeeAssignment::query()
            ->with(['position:id,title,code', 'organization:id,name', 'department:id,name'])
            ->where('employee_id', $employeeId)
            ->orderByDesc('start_date')
            ->get();

        return [
            'active' => $assignments->where('status', 'active')->values(),
            'ended' => $assignments->where('status', 'ended')->values(),
        ];
    }

    /** Atasan aktif pegawai, berurutan menurut prioritas hubungan (§21). */
    public function managersOf(HrEmployee|int $employee, ?string $onDate = null): Collection
    {
        $employeeId = $employee instanceof HrEmployee ? (int) $employee->getKey() : $employee;

        return HrEmployeeManager::query()
            ->with(['manager:id,employee_number,person_id', 'manager.person:id,full_name'])
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->effectiveOn($onDate ?? now()->toDateString())
            ->get()
            ->sortBy(fn (HrEmployeeManager $relation) => ManagerRelationshipType::tryFrom($relation->relationship_type)?->priority() ?? 9)
            ->values();
    }

    /** Bagan pelaporan: pegawai beserta bawahannya, siap digambar menjadi org chart. */
    public function reportingTree(HrEmployee|int $employee, int $maxDepth = 4, int $depth = 0): array
    {
        $model = $employee instanceof HrEmployee ? $employee : HrEmployee::query()->with('person')->findOrFail($employee);
        $primary = $this->currentPrimary($model);

        $node = [
            'id' => (int) $model->getKey(),
            'employee_number' => $model->employee_number,
            'name' => $model->displayName(),
            'position' => $primary?->position?->title ?? $model->position?->title,
            'position_code' => $primary?->position?->code,
            'organization' => $primary?->organization?->name ?? $model->organization?->name,
            'department' => $primary?->department?->name ?? $model->department?->name,
            'lifecycle_state' => $model->lifecycle_state,
            'children' => [],
        ];

        if ($depth >= $maxDepth) {
            return $node;
        }

        $reports = HrEmployee::query()
            ->with(['person:id,full_name', 'position:id,title,code'])
            ->where('manager_employee_id', $model->getKey())
            ->whereNotIn('lifecycle_state', ['inactive', 'archived'])
            ->orderBy('employee_number')
            ->get();

        $seen = [];

        foreach ($reports as $report) {
            if ((int) $report->getKey() === (int) $model->getKey() || in_array((int) $report->getKey(), $seen, true)) {
                continue;
            }

            $seen[] = (int) $report->getKey();
            $node['children'][] = $this->reportingTree($report, $maxDepth, $depth + 1);
        }

        return $node;
    }

    /* ── Bantu ──────────────────────────────────────────────────────────── */

    private function resolvePosition(int|string|null $positionId): ?HrPosition
    {
        if ($positionId === null || $positionId === '') {
            return null;
        }

        $position = HrPosition::query()->find($positionId);

        if ($position === null) {
            throw HrdValidationException::forField('position_id', 'Posisi tidak ditemukan.', 'hr.assignment.position_missing');
        }

        if (in_array($position->status, [HrPosition::STATUS_FROZEN, HrPosition::STATUS_ABOLISHED, HrPosition::STATUS_ARCHIVED], true)) {
            throw HrdValidationException::forField(
                'position_id',
                "Posisi \"{$position->title}\" sedang dibekukan/dimansuhkan sehingga tidak dapat diisi.",
                'hr.assignment.position_unavailable',
            );
        }

        return $position;
    }

    /** Formasi tidak boleh terisi melebihi jumlah kursi yang diizinkan (§49, §57). */
    private function assertHeadcountAvailable(HrPosition $position, HrEmployee $employee, string $startDate): void
    {
        $alreadyHolds = HrEmployeeAssignment::query()
            ->where('position_id', $position->getKey())
            ->where('employee_id', $employee->getKey())
            ->where('status', 'active')
            ->exists();

        if ($alreadyHolds) {
            return;
        }

        $filled = (int) HrEmployeeAssignment::query()
            ->where('position_id', $position->getKey())
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $startDate))
            ->distinct('employee_id')
            ->count('employee_id');

        if ($filled >= (int) $position->authorized_headcount) {
            throw HrdValidationException::forField(
                'position_id',
                "Formasi posisi \"{$position->title}\" sudah penuh ({$filled} dari {$position->authorized_headcount} kursi).",
                'hr.position.headcount_exceeded',
            );
        }
    }

    private function closePrimaryAssignments(HrEmployee $employee, string $startDate, User $actor, ?HrTransaction $transaction, string $reason): void
    {
        $open = HrEmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('is_primary', true)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();

        foreach ($open as $assignment) {
            // Penugasan yang mulai pada atau setelah tanggal baru berarti sudah
            // tergantikan sejak awal; sebelum itu berarti ditutup sehari sebelumnya.
            $closingDate = $assignment->start_date->toDateString() >= $startDate
                ? $assignment->start_date->toDateString()
                : date('Y-m-d', strtotime($startDate.' -1 day'));

            $assignment->forceFill([
                'end_date' => $closingDate,
                'status' => 'ended',
                'updated_by' => $actor->getKey(),
                'notes' => trim((string) $assignment->notes.' | '.$reason, ' |'),
            ])->save();

            if ($assignment->position_id !== null) {
                $position = HrPosition::query()->find($assignment->position_id);

                if ($position !== null) {
                    $this->positions->recalculate($position);
                }
            }

            $this->audit->log($actor, 'assignment.auto_ended', $assignment, null, $assignment->toArray(), $reason, $transaction?->getKey(), (int) $employee->getKey());
        }
    }

    private function closeManagerRelations(HrEmployee $employee, ManagerRelationshipType $relationship, string $startDate, User $actor, ?HrTransaction $transaction): void
    {
        $open = HrEmployeeManager::query()
            ->where('employee_id', $employee->getKey())
            ->where('relationship_type', $relationship->value)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();

        foreach ($open as $relation) {
            $relation->forceFill([
                'end_date' => date('Y-m-d', strtotime($startDate.' -1 day')),
                'status' => 'ended',
            ])->save();

            $this->audit->log($actor, 'manager.relation_ended', $relation, null, $relation->toArray(), null, $transaction?->getKey(), (int) $employee->getKey());
        }
    }

    /** Ringkasan atasan pada pegawai selalu mencerminkan hubungan berprioritas tertinggi. */
    private function refreshManagerSummary(HrEmployee $employee, User $actor): void
    {
        $managers = $this->managersOf($employee);
        $primary = $managers->first();

        $employee->forceFill([
            'manager_employee_id' => $primary?->manager_employee_id,
            'updated_by' => $actor->getKey(),
        ])->save();

        // Penugasan utama ikut menyesuaikan agar rantai pelaporan tetap konsisten.
        $primaryAssignment = $this->currentPrimary($employee);

        if ($primaryAssignment !== null && (int) ($primaryAssignment->manager_employee_id ?? 0) !== (int) ($primary?->manager_employee_id ?? 0)) {
            $primaryAssignment->forceFill(['manager_employee_id' => $primary?->manager_employee_id])->save();
        }
    }

    /** Pindahkan ringkasan pegawai agar mengikuti penugasan utama. */
    public function syncEmployeeSummary(HrEmployee $employee, HrEmployeeAssignment $assignment, User $actor): void
    {
        $employee->forceFill([
            'organization_id' => $assignment->organization_id,
            'department_id' => $assignment->department_id,
            'position_id' => $assignment->position_id,
            'location_id' => $assignment->location_id,
            'manager_employee_id' => $assignment->manager_employee_id,
            'updated_by' => $actor->getKey(),
            'version' => (int) $employee->version + 1,
        ])->save();
    }

    /** Atasan tidak boleh melingkar (§56 "circular manager hierarchy"). */
    public function assertNoManagerCycle(HrEmployee $employee, HrEmployee $manager): void
    {
        $visited = [(int) $employee->getKey()];
        $current = $manager;

        while ($current !== null) {
            $currentId = (int) $current->getKey();

            if (in_array($currentId, $visited, true)) {
                throw HrdValidationException::forField(
                    'manager_employee_id',
                    'Rantai atasan akan membentuk lingkaran.',
                    'hr.manager.cycle',
                );
            }

            $visited[] = $currentId;
            $current = $current->manager_id === null
                ? null
                : HrEmployee::query()->find($current->manager_id);
        }
    }

    private function describe(HrEmployeeAssignment $assignment): string
    {
        $parts = array_filter([
            $assignment->position?->title,
            $assignment->department?->name,
            $assignment->organization?->name,
        ]);

        return $parts === [] ? 'Penugasan' : implode(' — ', $parts);
    }
}
