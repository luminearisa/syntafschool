<?php

namespace App\Modules\Hrd\Workforce\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Workforce\Models\HrTimesheet;
use App\Modules\Hrd\Workforce\Models\HrTimesheetEntry;
use Illuminate\Support\Facades\DB;

/**
 * Timesheet: jam kerja per proyek/tugas.
 *
 * Dipakai untuk pembebanan biaya proyek, dasar lembur, dan analisis utilisasi —
 * itulah sebabnya entri menyimpan proyek, tugas, dan penanda dapat ditagih,
 * bukan hanya jumlah jam.
 */
class TimesheetService
{
    public function __construct(private readonly HrdPhase2Events $events) {}

    /** @param array<string, mixed> $attributes */
    public function create(HrEmployee|int $employee, array $attributes, ?User $actor = null): HrTimesheet
    {
        $employeeId = $employee instanceof HrEmployee ? (int) $employee->getKey() : $employee;

        return HrTimesheet::query()->create([
            'code' => sprintf('TS-%s-%04d', now()->format('Y'), HrTimesheet::query()->whereYear('created_at', now()->year)->count() + 1),
            'employee_id' => $employeeId,
            'period_type' => strtoupper((string) ($attributes['period_type'] ?? 'WEEKLY')),
            'period_start' => $attributes['period_start'] ?? now()->startOfWeek()->toDateString(),
            'period_end' => $attributes['period_end'] ?? now()->endOfWeek()->toDateString(),
            'status' => 'DRAFT',
            'project_ref' => $attributes['project_ref'] ?? null,
            'notes' => $attributes['notes'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function addEntry(HrTimesheet $timesheet, array $attributes, ?User $actor = null): HrTimesheetEntry
    {
        if (! in_array($timesheet->status, ['DRAFT', 'REJECTED'], true)) {
            throw HrdValidationException::forField('status', 'Timesheet yang sudah diajukan tidak dapat diubah.', 'timesheet.locked');
        }

        $hours = (float) ($attributes['hours'] ?? 0);

        if ($hours <= 0 || $hours > 24) {
            throw HrdValidationException::forField('hours', 'Jam kerja harus antara 0 dan 24.', 'timesheet.hours_invalid');
        }

        $entry = HrTimesheetEntry::query()->create([
            'timesheet_id' => $timesheet->getKey(),
            'employee_id' => $timesheet->employee_id,
            'work_date' => $attributes['work_date'] ?? now()->toDateString(),
            'hours' => $hours,
            'project_code' => $attributes['project_code'] ?? null,
            'task_code' => $attributes['task_code'] ?? null,
            'description' => $attributes['description'] ?? null,
            'is_billable' => (bool) ($attributes['is_billable'] ?? false),
            'cost_center' => $attributes['cost_center'] ?? null,
        ]);

        $this->recalculate($timesheet->refresh());

        return $entry;
    }

    public function submit(HrTimesheet $timesheet, User $actor): HrTimesheet
    {
        $hours = (float) $timesheet->entries()->sum('hours');

        if ($hours <= 0) {
            throw HrdValidationException::forField('entries', 'Timesheet tanpa jam kerja tidak dapat diajukan.', 'timesheet.empty');
        }

        $timesheet->forceFill(['status' => 'SUBMITTED', 'submitted_at' => now()])->save();

        $this->events->emit('WORKFORCE', 'timesheet.submitted', [
            'actor' => $actor,
            'employee_id' => $timesheet->employee_id,
            'subject_type' => HrTimesheet::class,
            'subject_id' => $timesheet->getKey(),
            'title' => 'Timesheet diajukan '.round($hours, 2).' jam',
        ]);

        return $timesheet->refresh();
    }

    public function decide(HrTimesheet $timesheet, User $actor, bool $approved, ?string $notes = null): HrTimesheet
    {
        if ($timesheet->status !== 'SUBMITTED') {
            throw HrdValidationException::forField('status', 'Hanya timesheet yang diajukan dapat diputuskan.', 'timesheet.not_submitted');
        }

        return DB::transaction(function () use ($timesheet, $actor, $approved, $notes) {
            $timesheet->forceFill([
                'status' => $approved ? 'APPROVED' : 'REJECTED',
                'approved_by' => $approved ? $actor->getKey() : null,
                'approved_at' => $approved ? now() : null,
                'notes' => $notes ?? $timesheet->notes,
            ])->save();

            $timesheet->entries()->update(['approved' => $approved]);

            $this->events->emit('WORKFORCE', $approved ? 'timesheet.approved' : 'timesheet.rejected', [
                'actor' => $actor,
                'employee_id' => $timesheet->employee_id,
                'subject_type' => HrTimesheet::class,
                'subject_id' => $timesheet->getKey(),
                'title' => 'Timesheet '.($approved ? 'disetujui' : 'ditolak'),
                'reason' => $notes,
            ]);

            return $timesheet->refresh();
        });
    }

    public function recalculate(HrTimesheet $timesheet): HrTimesheet
    {
        $entries = $timesheet->entries()->get();

        $timesheet->forceFill([
            'total_hours' => round((float) $entries->sum('hours'), 2),
            'billable_hours' => round((float) $entries->where('is_billable', true)->sum('hours'), 2),
        ])->save();

        return $timesheet->refresh();
    }

    /** @return array<string, mixed> */
    public function utilization(int $employeeId, string $from, string $to): array
    {
        $entries = HrTimesheetEntry::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('work_date', [$from, $to])
            ->whereHas('timesheet', fn ($query) => $query->whereIn('status', ['SUBMITTED', 'APPROVED']))
            ->get();

        $total = (float) $entries->sum('hours');
        $billable = (float) $entries->where('is_billable', true)->sum('hours');

        return [
            'total_hours' => round($total, 2),
            'billable_hours' => round($billable, 2),
            'utilization_percent' => $total > 0 ? round($billable / $total * 100, 2) : 0.0,
            'projects' => $entries->groupBy('project_code')->map(fn ($group) => round((float) $group->sum('hours'), 2))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function projectCosting(string $projectCode, float $hourlyRate): array
    {
        $entries = HrTimesheetEntry::query()
            ->where('project_code', $projectCode)
            ->where('approved', true)
            ->get();

        $hours = (float) $entries->sum('hours');

        return [
            'project_code' => $projectCode,
            'hours' => round($hours, 2),
            'employees' => $entries->pluck('employee_id')->unique()->count(),
            'cost' => round($hours * $hourlyRate, 2),
        ];
    }
}
