<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\AssignmentRole;
use App\Modules\Supervisi\Domain\Enums\InstrumentCategory;
use App\Modules\Supervisi\Domain\Enums\SupervisorKind;
use App\Modules\Supervisi\Models\SupAssignment;
use App\Modules\Supervisi\Models\SupSupervisor;
use App\Modules\Supervisi\Application\Support\SearchTerm;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Registry supervisor (§B keputusan desain).
 *
 * Sekolah memilih **siapa saja** yang boleh menilai/membina dari daftar
 * pegawai. Seluruh aturan berikut ditegakkan di server — bukan hanya di UI:
 *
 *  1. hanya pegawai aktif yang dapat didaftarkan,
 *  2. satu pegawai hanya punya satu baris registry,
 *  3. rekan sejawat/mentor tidak boleh memfinalkan nilai resmi,
 *  4. penugasan ditolak bila di luar masa aktif, di luar cakupan, kapasitas
 *     penuh, atau menilai diri sendiri.
 */
class SupervisorDirectoryService
{
    public function __construct(private readonly AuditLogger $audit) {}

    // ------------------------------------------------------------------
    // Registry
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function register(Employee $employee, array $data, ?User $actor = null): SupSupervisor
    {
        if (! $employee->is_active) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pegawai tidak aktif sehingga tidak dapat didaftarkan sebagai supervisor.',
            ]);
        }

        if (SupSupervisor::withTrashed()->where('employee_id', $employee->id)->exists()) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pegawai ini sudah terdaftar sebagai supervisor. Silakan ubah datanya.',
            ]);
        }

        $kind = SupervisorKind::from($data['kind'] ?? SupervisorKind::KEPALA_SEKOLAH->value);

        $supervisor = SupSupervisor::query()->create([
            'school_id' => $data['school_id'] ?? $employee->school_id,
            'employee_id' => $employee->id,
            'kind' => $kind->value,
            'scope_type' => $data['scope_type'] ?? null,
            'scope_id' => $data['scope_id'] ?? null,
            'subject_ids' => $data['subject_ids'] ?? null,
            'categories' => $data['categories'] ?? null,
            'can_finalize' => $this->resolveFinalize($kind, $data['can_finalize'] ?? null),
            'can_appraise' => $this->resolveAppraise($kind, $data['can_appraise'] ?? null),
            'max_active_cases' => $data['max_active_cases'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'active_from' => $data['active_from'] ?? null,
            'active_to' => $data['active_to'] ?? null,
            'certification_no' => $data['certification_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor?->id,
        ]);

        $this->audit->log('supervisor.registered', $supervisor, null, $supervisor->toArray(), null, $actor);

        return $supervisor->load('employee');
    }

    /** @param array<string,mixed> $data */
    public function update(SupSupervisor $supervisor, array $data, ?User $actor = null): SupSupervisor
    {
        $before = $supervisor->toArray();
        $kind = isset($data['kind']) ? SupervisorKind::from($data['kind']) : $supervisor->kind;

        $supervisor->fill(array_filter([
            'kind' => $data['kind'] ?? null,
            'scope_type' => $data['scope_type'] ?? null,
            'scope_id' => $data['scope_id'] ?? null,
            'subject_ids' => $data['subject_ids'] ?? null,
            'categories' => $data['categories'] ?? null,
            'max_active_cases' => $data['max_active_cases'] ?? null,
            'active_from' => $data['active_from'] ?? null,
            'active_to' => $data['active_to'] ?? null,
            'certification_no' => $data['certification_no'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('is_active', $data)) {
            $supervisor->is_active = (bool) $data['is_active'];
        }

        // Kewenangan tidak pernah melebihi batas jenis supervisor.
        $supervisor->can_finalize = $this->resolveFinalize(
            $kind,
            array_key_exists('can_finalize', $data) ? (bool) $data['can_finalize'] : $supervisor->can_finalize,
        );
        $supervisor->can_appraise = $this->resolveAppraise(
            $kind,
            array_key_exists('can_appraise', $data) ? (bool) $data['can_appraise'] : $supervisor->can_appraise,
        );

        $supervisor->save();

        $this->audit->log('supervisor.updated', $supervisor, $before, $supervisor->toArray(), null, $actor);

        return $supervisor->load('employee');
    }

    public function deactivate(SupSupervisor $supervisor, ?User $actor = null, ?string $reason = null): SupSupervisor
    {
        $before = $supervisor->toArray();
        $supervisor->forceFill(['is_active' => false])->save();

        $this->audit->log('supervisor.deactivated', $supervisor, $before, $supervisor->toArray(), $reason, $actor);

        return $supervisor;
    }

    /**
     * Calon supervisor: pegawai aktif yang belum terdaftar, disertai usulan
     * jenis kewenangan berdasarkan jabatan & peran akunnya.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function candidates(?string $search = null, int $limit = 50): Collection
    {
        $registered = SupSupervisor::withTrashed()->pluck('employee_id')->all();

        return Employee::query()
            ->where('is_active', true)
            ->when($registered !== [], fn ($query) => $query->whereNotIn('id', $registered))
            ->when(SearchTerm::like($search) !== null, function ($query) use ($search): void {
                $term = SearchTerm::like($search);
                $query->where(function ($inner) use ($term): void {
                    $inner->where('full_name', 'like', $term)
                        ->orWhere('nip', 'like', $term)
                        ->orWhere('position', 'like', $term);
                });
            })
            ->orderBy('full_name')
            ->limit($limit)
            ->get()
            ->map(fn (Employee $employee) => [
                'employee_id' => $employee->id,
                'full_name' => $employee->full_name,
                'nip' => $employee->nip,
                'position' => $employee->position,
                'employee_type' => $employee->employee_type,
                'suggested_kind' => $this->suggestKind($employee),
                'suggested_can_finalize' => $this->suggestKind($employee)->mayFinalize(),
            ]);
    }

    /**
     * Matriks cakupan: siapa menilai siapa, beserta peringatan tata kelola.
     *
     * @return array{rows: list<array<string,mixed>>, warnings: list<string>, summary: array<string,int>}
     */
    public function coverage(): array
    {
        $assignments = SupAssignment::query()
            ->with(['supervisor:id,full_name,position', 'supervisee:id,full_name,position,employee_type', 'program:id,title,status'])
            ->active()
            ->get();

        $rows = $assignments->map(fn (SupAssignment $assignment) => [
            'assignment_id' => $assignment->id,
            'program' => $assignment->program?->title,
            'supervisor' => $assignment->supervisor?->full_name,
            'supervisee' => $assignment->supervisee?->full_name,
            'employee_type' => $assignment->supervisee?->employee_type,
            'role' => $assignment->role->value,
            'approach' => $assignment->approach->value,
            'min_visits' => $assignment->min_visits,
        ])->all();

        $warnings = [];
        $perSupervisee = $assignments->groupBy('supervisee_employee_id');
        $perSupervisor = $assignments->groupBy('supervisor_employee_id');

        foreach ($perSupervisee as $superviseeId => $group) {
            $name = $group->first()->supervisee?->full_name ?? "Pegawai #{$superviseeId}";
            $supervisors = $group->pluck('supervisor_employee_id')->unique();

            if ($supervisors->contains((int) $superviseeId)) {
                $warnings[] = "{$name} tercatat menilai dirinya sendiri — penugasan ini harus dibatalkan.";
            }

            if ($supervisors->count() > 2) {
                $warnings[] = "{$name} dinilai oleh {$supervisors->count()} supervisor berbeda; pertimbangkan menyederhanakan penugasan.";
            }
        }

        foreach ($perSupervisor as $supervisorId => $group) {
            $registry = SupSupervisor::query()->where('employee_id', $supervisorId)->first();
            $name = $group->first()->supervisor?->full_name ?? "Pegawai #{$supervisorId}";

            if ($registry === null) {
                $warnings[] = "{$name} memiliki penugasan tetapi belum terdaftar di pengaturan supervisor.";
            } elseif ($registry->max_active_cases !== null && $group->pluck('supervisee_employee_id')->unique()->count() > $registry->max_active_cases) {
                $warnings[] = "Beban {$name} melebihi kapasitas ({$registry->max_active_cases} orang).";
            }
        }

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'summary' => [
                'supervisors_active' => SupSupervisor::query()->active()->count(),
                'assignments_active' => $assignments->count(),
                'supervisees' => $perSupervisee->count(),
            ],
        ];
    }

    /**
     * Pastikan seseorang boleh ditugaskan menilai pegawai tertentu.
     *
     * @throws ValidationException
     */
    public function assertAssignable(
        SupSupervisor $supervisor,
        Employee $supervisee,
        ?InstrumentCategory $category = null,
        ?AssignmentRole $role = null,
    ): void {
        if (! $supervisor->is_active) {
            $this->fail('supervisor_employee_id', 'Supervisor sedang tidak aktif.');
        }

        if (($supervisor->active_from !== null && now()->lt($supervisor->active_from))
            || ($supervisor->active_to !== null && now()->gt($supervisor->active_to))) {
            $this->fail('supervisor_employee_id', 'Masa berlaku kewenangan supervisor sudah berakhir atau belum dimulai.');
        }

        if ((int) $supervisor->employee_id === (int) $supervisee->id) {
            $this->fail('supervisor_employee_id', 'Seseorang tidak boleh menilai dirinya sendiri.');
        }

        if ($category !== null && is_array($supervisor->categories) && $supervisor->categories !== []
            && ! in_array($category->value, $supervisor->categories, true)) {
            $this->fail('supervisor_employee_id', "Supervisor tidak diberi kewenangan untuk instrumen kategori {$category->label()}.");
        }

        if ($supervisor->max_active_cases !== null) {
            $active = SupAssignment::query()
                ->where('supervisor_employee_id', $supervisor->employee_id)
                ->where('is_active', true)
                ->distinct('supervisee_employee_id')
                ->count('supervisee_employee_id');

            if ($active >= $supervisor->max_active_cases) {
                $this->fail('supervisor_employee_id', "Kapasitas supervisor penuh ({$active}/{$supervisor->max_active_cases}).");
            }
        }

        if ($role === AssignmentRole::MODERATOR && ! $supervisor->mayFinalize()) {
            $this->fail('role', 'Moderator harus supervisor dengan kewenangan penilaian resmi.');
        }
    }

    /** Supervisor yang memenuhi syarat untuk menilai pegawai tertentu. */
    public function eligibleFor(Employee $supervisee, ?InstrumentCategory $category = null): Collection
    {
        return SupSupervisor::query()
            ->with('employee:id,full_name,position,nip')
            ->active()
            ->get()
            ->map(function (SupSupervisor $supervisor) use ($supervisee, $category) {
                try {
                    $this->assertAssignable($supervisor, $supervisee, $category);
                    $supervisor->setAttribute('eligible', true);
                    $supervisor->setAttribute('reason', null);
                } catch (ValidationException $exception) {
                    $supervisor->setAttribute('eligible', false);
                    $supervisor->setAttribute('reason', collect($exception->errors())->flatten()->first());
                }

                return $supervisor;
            })
            ->sortByDesc('eligible')
            ->values();
    }

    // ------------------------------------------------------------------
    // Pembantu
    // ------------------------------------------------------------------

    protected function resolveFinalize(SupervisorKind $kind, ?bool $requested): bool
    {
        if (! $kind->mayFinalize()) {
            return false; // peer/mentor/koordinator tidak pernah boleh finalisasi
        }

        return $requested ?? true;
    }

    protected function resolveAppraise(SupervisorKind $kind, ?bool $requested): bool
    {
        if (! $kind->mayAppraise()) {
            return false;
        }

        return $requested ?? true;
    }

    /** Usulan jenis supervisor dari jabatan & peran akun pegawai. */
    public function suggestKind(Employee $employee): SupervisorKind
    {
        $position = strtolower((string) $employee->position);
        $roles = $employee->user?->getRoleNames()->all() ?? [];

        return match (true) {
            str_contains($position, 'pengawas') => SupervisorKind::PENGAWAS,
            str_contains($position, 'kepala sekolah') || str_contains($position, 'kepala madrasah'),
            in_array('kepala-sekolah', $roles, true) => SupervisorKind::KEPALA_SEKOLAH,
            str_contains($position, 'wakil') || str_contains($position, 'waka') => SupervisorKind::WAKASEK,
            str_contains($position, 'koordinator') || str_contains($position, 'kepala unit') => SupervisorKind::KOORDINATOR,
            default => SupervisorKind::MENTOR,
        };
    }

    /** @throws ValidationException */
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
