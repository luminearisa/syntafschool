<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Models\Employee;
use App\Modules\Supervisi\Application\Services\AssignmentService;
use App\Modules\Supervisi\Domain\Enums\AssignmentRole;
use App\Modules\Supervisi\Domain\Enums\SupervisionApproach;
use App\Modules\Supervisi\Models\SupAssignment;
use App\Modules\Supervisi\Models\SupProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Penugasan supervisor: siapa menilai siapa, dengan pendekatan apa. */
class SupAssignmentController extends SupController
{
    public function __construct(private readonly AssignmentService $assignments) {}

    public function index(Request $request): JsonResponse
    {
        $assignments = SupAssignment::query()
            ->with(['supervisor:id,full_name,position', 'supervisee:id,full_name,position,employee_type', 'program:id,title,status'])
            ->when($request->filled('program_id'), fn ($q) => $q->where('program_id', $request->integer('program_id')))
            ->when($request->filled('supervisor_employee_id'), fn ($q) => $q->where('supervisor_employee_id', $request->integer('supervisor_employee_id')))
            ->when($request->filled('supervisee_employee_id'), fn ($q) => $q->where('supervisee_employee_id', $request->integer('supervisee_employee_id')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->through(fn (SupAssignment $assignment) => $this->payload($assignment));

        return $this->successResponse($assignments, 'Daftar penugasan supervisi');
    }

    public function store(Request $request, SupProgram $program): JsonResponse
    {
        $data = $request->validate($this->rules());

        $assignment = $this->assignments->create($program, $data, $this->actor($request));

        return $this->createdResponse($this->payload($assignment), 'Penugasan supervisi dibuat');
    }

    public function update(Request $request, SupAssignment $assignment): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));

        return $this->successResponse(
            $this->payload($this->assignments->update($assignment, $data, $this->actor($request))),
            'Penugasan diperbarui',
        );
    }

    public function destroy(Request $request, SupAssignment $assignment): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->assignments->deactivate($assignment, $this->actor($request), $data['reason'] ?? null);

        return $this->successResponse(null, 'Penugasan dinonaktifkan');
    }

    /** Beban & rekap penugasan per supervisor (untuk layar pengaturan). */
    public function workload(Request $request): JsonResponse
    {
        $data = $request->validate(['program_id' => ['nullable', 'integer', 'exists:sup_programs,id']]);

        $rows = SupAssignment::query()
            ->when(isset($data['program_id']), fn ($q) => $q->where('program_id', $data['program_id']))
            ->where('is_active', true)
            ->selectRaw('supervisor_employee_id, count(distinct supervisee_employee_id) as supervisees, count(*) as assignments')
            ->groupBy('supervisor_employee_id')
            ->get()
            ->map(function ($row) {
                $employee = Employee::query()->find($row->supervisor_employee_id);

                return [
                    'supervisor_employee_id' => $row->supervisor_employee_id,
                    'supervisor' => $employee?->full_name,
                    'position' => $employee?->position,
                    'supervisees' => (int) $row->supervisees,
                    'assignments' => (int) $row->assignments,
                ];
            });

        return $this->successResponse($rows, 'Beban penugasan supervisor');
    }

    /** @return array<string,mixed> */
    protected function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'supervisor_employee_id' => [$required, 'integer', 'exists:employees,id'],
            'supervisee_employee_id' => [$required, 'integer', 'exists:employees,id'],
            'role' => ['nullable', 'string', 'in:'.implode(',', AssignmentRole::values())],
            'approach' => ['nullable', 'string', 'in:'.implode(',', SupervisionApproach::values())],
            'scope_type' => ['nullable', 'string', 'max:30'],
            'scope_id' => ['nullable', 'integer'],
            'allowed_instruments' => ['nullable', 'array'],
            'allowed_instruments.*' => ['integer', 'exists:sup_instruments,id'],
            'min_visits' => ['nullable', 'integer', 'min:1', 'max:20'],
            'target_visits' => ['nullable', 'integer', 'min:1', 'max:20'],
            'active_from' => ['nullable', 'date'],
            'active_to' => ['nullable', 'date', 'after_or_equal:active_from'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string,mixed> */
    protected function payload(SupAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'program_id' => $assignment->program_id,
            'program' => $assignment->program?->title,
            'supervisor_employee_id' => $assignment->supervisor_employee_id,
            'supervisor' => $assignment->supervisor?->full_name,
            'supervisee_employee_id' => $assignment->supervisee_employee_id,
            'supervisee' => $assignment->supervisee?->full_name,
            'employee_type' => $assignment->supervisee?->employee_type,
            'role' => $assignment->role->value,
            'role_label' => $assignment->role->label(),
            'approach' => $assignment->approach->value,
            'approach_label' => $assignment->approach->label(),
            'approach_guidance' => $assignment->approach->guidance(),
            'min_visits' => $assignment->min_visits,
            'target_visits' => $assignment->target_visits,
            'is_active' => $assignment->is_active,
            'active_from' => $assignment->active_from?->toDateString(),
            'active_to' => $assignment->active_to?->toDateString(),
        ];
    }
}
