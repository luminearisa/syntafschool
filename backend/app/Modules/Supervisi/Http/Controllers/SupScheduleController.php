<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\AuditLogger;
use App\Modules\Supervisi\Application\Services\SupervisorDirectoryService;
use App\Modules\Supervisi\Domain\Enums\ActivityKind;
use App\Modules\Supervisi\Domain\Enums\ScheduleStatus;
use App\Modules\Supervisi\Models\SupAssignment;
use App\Modules\Supervisi\Models\SupProgram;
use App\Modules\Supervisi\Models\SupSchedule;
use App\Modules\Supervisi\Models\SupSupervisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Jadwal & undangan supervisi. */
class SupScheduleController extends SupController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SupervisorDirectoryService $directory,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $employeeId = $actor->userable instanceof \App\Models\Employee ? (int) $actor->userable->id : null;
        $canManage = $actor->can('supervisi.schedule.manage');

        $schedules = SupSchedule::query()
            ->with(['supervisor:id,full_name', 'supervisee:id,full_name,employee_type', 'program:id,title', 'instrumentVersion:id,version,instrument_id'])
            // Guru/staf hanya melihat jadwal yang menyangkut dirinya (privacy, §A8).
            ->when(! $canManage, fn ($query) => $query->where(function ($inner) use ($employeeId): void {
                if ($employeeId === null) {
                    $inner->whereRaw('1 = 0');

                    return;
                }

                $inner->where('supervisee_employee_id', $employeeId)
                    ->orWhere('supervisor_employee_id', $employeeId);
            }))
            ->when($request->filled('program_id'), fn ($q) => $q->where('program_id', $request->integer('program_id')))
            ->when($request->filled('supervisor_employee_id'), fn ($q) => $q->where('supervisor_employee_id', $request->integer('supervisor_employee_id')))
            ->when($request->filled('supervisee_employee_id'), fn ($q) => $q->where('supervisee_employee_id', $request->integer('supervisee_employee_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('activity_kind'), fn ($q) => $q->where('activity_kind', $request->string('activity_kind')))
            ->when($request->filled('from'), fn ($q) => $q->where('planned_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('planned_at', '<=', $request->date('to')))
            ->orderBy('planned_at')
            ->paginate($this->perPage($request, 25))
            ->through(fn (SupSchedule $schedule) => $this->payload($schedule));

        return $this->successResponse($schedules, 'Jadwal supervisi');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $program = SupProgram::query()->findOrFail($data['program_id']);
        $actor = $this->actor($request);

        $assignment = isset($data['assignment_id']) ? SupAssignment::query()->find($data['assignment_id']) : null;
        $this->assertGovernance($program, $data, $assignment);

        $schedule = SupSchedule::query()->create([
            ...$data,
            'assignment_id' => $assignment?->id,
            'status' => ScheduleStatus::PLANNED->value,
            'created_by' => $actor->id,
        ]);

        $this->audit->log('schedule.created', $schedule, null, $schedule->toArray(), null, $actor);

        return $this->createdResponse($this->payload($schedule->load(['supervisor', 'supervisee'])), 'Jadwal supervisi dibuat');
    }

    public function update(Request $request, SupSchedule $schedule): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));
        $before = $schedule->toArray();

        $schedule->fill($data)->save();
        $this->audit->log('schedule.updated', $schedule, $before, $schedule->toArray(), null, $this->actor($request));

        return $this->successResponse($this->payload($schedule->load(['supervisor', 'supervisee'])), 'Jadwal diperbarui');
    }

    /** Ubah jadwal beserta alasan (tercatat di audit). */
    public function reschedule(Request $request, SupSchedule $schedule): JsonResponse
    {
        $data = $request->validate([
            'planned_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $before = $schedule->toArray();
        $schedule->forceFill([
            'planned_at' => $data['planned_at'],
            'duration_minutes' => $data['duration_minutes'] ?? $schedule->duration_minutes,
            'status' => ScheduleStatus::RESCHEDULED->value,
        ])->save();

        $this->audit->log('schedule.rescheduled', $schedule, $before, $schedule->toArray(), $data['reason'], $this->actor($request));

        return $this->successResponse($this->payload($schedule), 'Jadwal diubah');
    }

    public function cancel(Request $request, SupSchedule $schedule): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $before = $schedule->toArray();

        $schedule->forceFill([
            'status' => ScheduleStatus::CANCELLED->value,
            'cancel_reason' => $data['reason'],
        ])->save();

        $this->audit->log('schedule.cancelled', $schedule, $before, $schedule->toArray(), $data['reason'], $this->actor($request));

        return $this->successResponse($this->payload($schedule), 'Jadwal dibatalkan');
    }

    /** Tandai undangan sudah dikirim (pengingat H-1 memakai kolom `reminded_at`). */
    public function invite(Request $request, SupSchedule $schedule): JsonResponse
    {
        $before = $schedule->toArray();
        $schedule->forceFill([
            'status' => ScheduleStatus::INVITED->value,
            'invited_at' => now(),
        ])->save();

        $this->audit->log('schedule.invited', $schedule, $before, $schedule->toArray(), null, $this->actor($request));

        return $this->successResponse($this->payload($schedule), 'Undangan supervisi dikirim');
    }

    // ------------------------------------------------------------------

    /** @param array<string,mixed> $data */
    protected function assertGovernance(SupProgram $program, array $data, ?SupAssignment $assignment): void
    {
        if ((int) $data['supervisor_employee_id'] === (int) $data['supervisee_employee_id']) {
            throw ValidationException::withMessages([
                'supervisor_employee_id' => 'Supervisor dan yang disupervisi tidak boleh orang yang sama.',
            ]);
        }

        $registry = SupSupervisor::query()->where('employee_id', $data['supervisor_employee_id'])->first();

        if ($registry === null) {
            throw ValidationException::withMessages([
                'supervisor_employee_id' => 'Pegawai ini belum terdaftar sebagai supervisor (Pengaturan Supervisor).',
            ]);
        }

        $activity = ActivityKind::from($data['activity_kind'] ?? ActivityKind::CLASS_OBSERVATION->value);

        if ($activity->requiresConferences() && $registry->kind->value === 'PEER') {
            throw ValidationException::withMessages([
                'activity_kind' => 'Supervisi klinis/observasi penuh sebaiknya dilakukan penilai resmi; gunakan jenis kegiatan “Supervisi Sejawat” untuk rekan sejawat.',
            ]);
        }

        if ($program->status->value === 'CLOSED') {
            throw ValidationException::withMessages([
                'program_id' => 'Program sudah ditutup sehingga tidak dapat dijadwalkan lagi.',
            ]);
        }

        if ($assignment !== null && (int) $assignment->program_id !== (int) $program->id) {
            throw ValidationException::withMessages([
                'assignment_id' => 'Penugasan yang dipilih bukan milik program ini.',
            ]);
        }
    }

    /** @return array<string,mixed> */
    protected function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'program_id' => [$required, 'integer', 'exists:sup_programs,id'],
            'assignment_id' => ['nullable', 'integer', 'exists:sup_assignments,id'],
            'supervisor_employee_id' => [$required, 'integer', 'exists:employees,id'],
            'supervisee_employee_id' => [$required, 'integer', 'exists:employees,id'],
            'activity_kind' => ['nullable', 'string', 'in:'.implode(',', ActivityKind::values())],
            'instrument_version_id' => ['nullable', 'integer', 'exists:sup_instrument_versions,id'],
            'subject_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'focus' => ['nullable', 'string', 'max:500'],
            'planned_at' => [$required, 'date'],
            'window_end' => ['nullable', 'date', 'after_or_equal:planned_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'location' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:'.implode(',', ScheduleStatus::values())],
        ];
    }

    /** @return array<string,mixed> */
    protected function payload(SupSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'program_id' => $schedule->program_id,
            'program' => $schedule->program?->title,
            'assignment_id' => $schedule->assignment_id,
            'supervisor_employee_id' => $schedule->supervisor_employee_id,
            'supervisor' => $schedule->supervisor?->full_name,
            'supervisee_employee_id' => $schedule->supervisee_employee_id,
            'supervisee' => $schedule->supervisee?->full_name,
            'employee_type' => $schedule->supervisee?->employee_type,
            'activity_kind' => $schedule->activity_kind->value,
            'activity_kind_label' => $schedule->activity_kind->label(),
            'instrument_version_id' => $schedule->instrument_version_id,
            'focus' => $schedule->focus,
            'planned_at' => $schedule->planned_at->toIso8601String(),
            'window_end' => $schedule->window_end?->toIso8601String(),
            'duration_minutes' => $schedule->duration_minutes,
            'location' => $schedule->location,
            'status' => $schedule->status->value,
            'status_label' => $schedule->status->label(),
            'invited_at' => $schedule->invited_at?->toIso8601String(),
        ];
    }
}
