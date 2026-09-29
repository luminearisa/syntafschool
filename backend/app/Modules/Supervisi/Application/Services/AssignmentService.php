<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\AssignmentRole;
use App\Modules\Supervisi\Domain\Enums\InstrumentCategory;
use App\Modules\Supervisi\Domain\Enums\SupervisionApproach;
use App\Modules\Supervisi\Models\SupAssignment;
use App\Modules\Supervisi\Models\SupProgram;
use App\Modules\Supervisi\Models\SupSupervisor;
use Illuminate\Validation\ValidationException;

/**
 * Penugasan supervisor.
 *
 * Hanya pegawai yang **terdaftar di registry supervisor** yang dapat ditugaskan
 * — inilah yang membuat “pengaturan supervisor” di aplikasi benar-benar
 * mengikat, bukan sekadar daftar hiasan.
 */
class AssignmentService
{
    public function __construct(
        private readonly SupervisorDirectoryService $supervisors,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(SupProgram $program, array $data, ?User $actor = null): SupAssignment
    {
        $supervisee = Employee::query()->findOrFail($data['supervisee_employee_id']);
        $registry = $this->registryFor((int) $data['supervisor_employee_id']);
        $role = AssignmentRole::from($data['role'] ?? AssignmentRole::UTAMA->value);

        $this->supervisors->assertAssignable($registry, $supervisee, null, $role);

        if ($program->starts_at !== null && $data['active_from'] ?? null === null) {
            $data['active_from'] = $program->starts_at->toDateString();
        }
        if ($program->ends_at !== null && ($data['active_to'] ?? null) === null) {
            $data['active_to'] = $program->ends_at->toDateString();
        }

        $assignment = SupAssignment::query()->updateOrCreate([
            'program_id' => $program->id,
            'supervisor_employee_id' => $registry->employee_id,
            'supervisee_employee_id' => $supervisee->id,
            'role' => $role->value,
        ], [
            'approach' => $data['approach'] ?? SupervisionApproach::COLLABORATIVE->value,
            'scope_type' => $data['scope_type'] ?? $registry->scope_type,
            'scope_id' => $data['scope_id'] ?? $registry->scope_id,
            'allowed_instruments' => $data['allowed_instruments'] ?? null,
            'min_visits' => $data['min_visits'] ?? 1,
            'target_visits' => $data['target_visits'] ?? null,
            'active_from' => $data['active_from'] ?? null,
            'active_to' => $data['active_to'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor?->id,
        ]);

        $this->audit->log('assignment.saved', $assignment, null, $assignment->toArray(), null, $actor, [
            'program' => $program->title,
        ]);

        return $assignment->load(['supervisor', 'supervisee']);
    }

    /** @param array<string,mixed> $data */
    public function update(SupAssignment $assignment, array $data, ?User $actor = null): SupAssignment
    {
        $before = $assignment->toArray();

        if (isset($data['supervisor_employee_id'])) {
            $registry = $this->registryFor((int) $data['supervisor_employee_id']);
            $this->supervisors->assertAssignable($registry, $assignment->supervisee()->firstOrFail(), null, $assignment->role);
            $assignment->supervisor_employee_id = $registry->employee_id;
        }

        $assignment->fill(array_filter([
            'approach' => $data['approach'] ?? null,
            'min_visits' => $data['min_visits'] ?? null,
            'target_visits' => $data['target_visits'] ?? null,
            'active_from' => $data['active_from'] ?? null,
            'active_to' => $data['active_to'] ?? null,
            'notes' => $data['notes'] ?? null,
            'allowed_instruments' => $data['allowed_instruments'] ?? null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('is_active', $data)) {
            $assignment->is_active = (bool) $data['is_active'];
        }

        $assignment->save();

        $this->audit->log('assignment.updated', $assignment, $before, $assignment->toArray(), null, $actor);

        return $assignment->load(['supervisor', 'supervisee']);
    }

    public function deactivate(SupAssignment $assignment, ?User $actor = null, ?string $reason = null): SupAssignment
    {
        $before = $assignment->toArray();
        $assignment->forceFill(['is_active' => false])->save();

        $this->audit->log('assignment.deactivated', $assignment, $before, $assignment->toArray(), $reason, $actor);

        return $assignment;
    }

    /** Apakah karyawan ini punya penugasan aktif (dasar izin menilai). */
    public function canSupervise(int $supervisorEmployeeId, int $superviseeEmployeeId): bool
    {
        return SupAssignment::query()
            ->where('supervisor_employee_id', $supervisorEmployeeId)
            ->where('supervisee_employee_id', $superviseeEmployeeId)
            ->where('is_active', true)
            ->exists();
    }

    /** Kategori instrumen yang boleh dipakai penugasan ini. */
    public function allowedCategories(SupAssignment $assignment): array
    {
        $ids = $assignment->allowed_instruments ?? [];

        if ($ids === []) {
            return InstrumentCategory::values();
        }

        return \App\Modules\Supervisi\Models\SupInstrument::query()
            ->whereIn('id', $ids)
            ->pluck('category')
            ->map(fn ($category) => $category instanceof InstrumentCategory ? $category->value : (string) $category)
            ->unique()
            ->values()
            ->all();
    }

    /** @throws ValidationException */
    protected function registryFor(int $employeeId): SupSupervisor
    {
        $registry = SupSupervisor::query()->where('employee_id', $employeeId)->first();

        if ($registry === null) {
            throw ValidationException::withMessages([
                'supervisor_employee_id' => 'Pegawai ini belum terdaftar sebagai supervisor. Tambahkan dulu di Pengaturan Supervisor.',
            ]);
        }

        return $registry;
    }
}
