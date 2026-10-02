<?php

namespace App\Modules\Hrd\Workforce\Services;

use App\Models\User;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Workforce\Models\HrEmployeeSchedule;
use App\Modules\Hrd\Workforce\Models\HrShift;
use App\Modules\Hrd\Workforce\Models\HrShiftRotation;
use App\Modules\Hrd\Workforce\Models\HrShiftRotationPattern;
use App\Modules\Hrd\Workforce\Models\HrWorkSchedule;
use App\Modules\Hrd\Workforce\Models\HrWorkScheduleDay;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Jadwal kerja, shift, dan rotasi.
 *
 * Shift lintas tengah malam (22:00 → 06:00) adalah aturan pertama, bukan
 * pengecualian: jam selesai yang lebih kecil daripada jam mulai berarti hari
 * berikutnya, dan presensi memakai perhitungan yang sama sehingga petugas
 * keamanan malam tidak pernah tercatat "pulang sebelum masuk".
 */
class ScheduleService
{
    public function __construct() {}

    /** @param array<string, mixed> $attributes */
    public function createShift(array $attributes, User $actor): HrShift
    {
        $start = (string) ($attributes['start_time'] ?? '08:00');
        $end = (string) ($attributes['end_time'] ?? '16:00');

        $crossMidnight = array_key_exists('cross_midnight', $attributes)
            ? (bool) $attributes['cross_midnight']
            : $this->isCrossMidnight($start, $end);

        $shift = HrShift::query()->create([
            'code' => strtoupper((string) ($attributes['code'] ?? 'SHIFT-'.now()->format('His'))),
            'name' => $attributes['name'] ?? 'Shift',
            'start_time' => $start,
            'end_time' => $end,
            'break_start' => $attributes['break_start'] ?? null,
            'break_end' => $attributes['break_end'] ?? null,
            'grace_minutes' => (int) ($attributes['grace_minutes'] ?? config('hrd_phase2.attendance.late_grace_minutes', 10)),
            'cross_midnight' => $crossMidnight,
            'shift_type' => strtoupper((string) ($attributes['shift_type'] ?? 'CUSTOM')),
            'total_hours' => $this->hoursBetween($start, $end, $crossMidnight, $attributes['break_minutes'] ?? 0),
            'organization_id' => $attributes['organization_id'] ?? null,
            'is_active' => true,
        ]);

        return $shift;
    }

    /** @param array<string, mixed> $attributes */
    public function createSchedule(array $attributes, User $actor): HrWorkSchedule
    {
        $workingDays = $attributes['working_days'] ?? [1, 2, 3, 4, 5];

        return DB::transaction(function () use ($attributes, $workingDays) {
            $schedule = HrWorkSchedule::query()->create([
                'code' => strtoupper((string) ($attributes['code'] ?? 'WS-'.now()->format('His'))),
                'name' => $attributes['name'] ?? 'Jadwal Kerja',
                'schedule_type' => strtoupper((string) ($attributes['schedule_type'] ?? 'FIXED')),
                'organization_id' => $attributes['organization_id'] ?? null,
                'department_id' => $attributes['department_id'] ?? null,
                'weekly_hours' => $attributes['weekly_hours'] ?? count($workingDays) * (float) config('hrd_phase2.payroll.working_hours_per_day', 8),
                'working_days' => array_map('intval', $workingDays),
                'flexible_start' => $attributes['flexible_start'] ?? null,
                'flexible_end' => $attributes['flexible_end'] ?? null,
                'core_start' => $attributes['core_start'] ?? null,
                'core_end' => $attributes['core_end'] ?? null,
                'is_default' => (bool) ($attributes['is_default'] ?? false),
                'effective_from' => $attributes['effective_from'] ?? now()->startOfYear()->toDateString(),
                'effective_to' => $attributes['effective_to'] ?? null,
                'meta' => $attributes['meta'] ?? null,
            ]);

            foreach ($attributes['days'] ?? [] as $day) {
                HrWorkScheduleDay::query()->create([
                    'schedule_id' => $schedule->getKey(),
                    'weekday' => (int) $day['weekday'],
                    'is_working' => (bool) ($day['is_working'] ?? true),
                    'shift_id' => $day['shift_id'] ?? null,
                    'start_time' => $day['start_time'] ?? '08:00',
                    'end_time' => $day['end_time'] ?? '16:00',
                    'break_minutes' => (int) ($day['break_minutes'] ?? 60),
                    'hours' => $day['hours'] ?? 7,
                ]);
            }

            return $schedule;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createRotation(array $attributes, User $actor): HrShiftRotation
    {
        return DB::transaction(function () use ($attributes) {
            $rotation = HrShiftRotation::query()->create([
                'code' => strtoupper((string) ($attributes['code'] ?? 'ROT-'.now()->format('His'))),
                'name' => $attributes['name'] ?? 'Rotasi Shift',
                'pattern' => strtoupper((string) ($attributes['pattern'] ?? 'WEEKLY')),
                'cycle_weeks' => (int) ($attributes['cycle_weeks'] ?? 3),
                'organization_id' => $attributes['organization_id'] ?? null,
                'meta' => $attributes['meta'] ?? null,
            ]);

            foreach ($attributes['patterns'] ?? [] as $pattern) {
                HrShiftRotationPattern::query()->create([
                    'rotation_id' => $rotation->getKey(),
                    'week_index' => (int) ($pattern['week_index'] ?? 1),
                    'weekday' => (int) ($pattern['weekday'] ?? 1),
                    'shift_id' => $pattern['shift_id'] ?? null,
                    'label' => $pattern['label'] ?? null,
                ]);
            }

            return $rotation;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function assign(HrEmployee $employee, array $attributes, User $actor): HrEmployeeSchedule
    {
        $scheduleId = $attributes['schedule_id'] ?? null;

        if ($scheduleId === null) {
            $scheduleId = HrWorkSchedule::query()->where('is_default', true)->value('id')
                ?? HrWorkSchedule::query()->orderBy('id')->value('id');
        }

        if ($scheduleId === null) {
            throw HrdValidationException::forField('schedule_id', 'Belum ada jadwal kerja yang dapat ditugaskan.', 'workforce.schedule_missing');
        }

        $effectiveFrom = $attributes['effective_from'] ?? now()->toDateString();

        // Penugasan sebelumnya ditutup, bukan ditimpa — riwayat jadwal tetap utuh.
        HrEmployeeSchedule::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $effectiveFrom))
            ->get()
            ->each(function (HrEmployeeSchedule $existing) use ($effectiveFrom) {
                $existing->forceFill([
                    'status' => 'ENDED',
                    'effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString(),
                ])->save();
            });

        return HrEmployeeSchedule::query()->create([
            'employee_id' => $employee->getKey(),
            'schedule_id' => $scheduleId,
            'shift_id' => $attributes['shift_id'] ?? null,
            'rotation_id' => $attributes['rotation_id'] ?? null,
            'effective_from' => $effectiveFrom,
            'effective_to' => $attributes['effective_to'] ?? null,
            'weekday_overrides' => $attributes['weekday_overrides'] ?? null,
            'is_primary' => (bool) ($attributes['is_primary'] ?? true),
            'source' => strtoupper((string) ($attributes['source'] ?? 'MANUAL')),
            'status' => 'ACTIVE',
            'assigned_by' => $actor->getKey(),
            'notes' => $attributes['notes'] ?? null,
        ]);
    }

    /**
     * Jadwal yang berlaku untuk pegawai pada satu tanggal, beserta jam
     * masuk/pulang yang diharapkan.
     *
     * @return array{schedule: ?HrWorkSchedule, shift: ?HrShift, is_working: bool, start: ?Carbon, end: ?Carbon, source: string}
     */
    public function resolveFor(int $employeeId, string|Carbon $date): array
    {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);

        $assignment = HrEmployeeSchedule::query()
            ->with(['schedule.days', 'shift'])
            ->where('employee_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $day->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $day->toDateString()))
            ->orderByDesc('is_primary')
            ->first();

        if ($assignment === null) {
            return ['schedule' => null, 'shift' => null, 'is_working' => false, 'start' => null, 'end' => null, 'source' => 'NONE'];
        }

        $schedule = $assignment->schedule;
        $override = is_array($assignment->weekday_overrides) ? ($assignment->weekday_overrides[(string) $day->dayOfWeekIso] ?? null) : null;

        $isWorking = $override !== null
            ? true
            : in_array($day->dayOfWeekIso, array_map('intval', (array) $schedule?->working_days ?? [1, 2, 3, 4, 5]), true);

        $shift = $assignment->shift;

        if ($shift === null && $schedule !== null) {
            $dayRow = $schedule->days->firstWhere('weekday', $day->dayOfWeekIso);

            if ($dayRow !== null) {
                $isWorking = $isWorking && (bool) $dayRow->is_working;

                if ($dayRow->shift_id !== null) {
                    $shift = HrShift::query()->find($dayRow->shift_id);
                }

                if ($shift === null && $dayRow->start_time !== null) {
                    return [
                        'schedule' => $schedule,
                        'shift' => null,
                        'is_working' => $isWorking,
                        'start' => $day->copy()->setTimeFromTimeString((string) $dayRow->start_time),
                        'end' => $day->copy()->setTimeFromTimeString((string) $dayRow->end_time),
                        'source' => $assignment->source,
                    ];
                }
            }
        }

        if ($shift !== null) {
            $window = $this->windowFor($shift, $day);

            return [
                'schedule' => $schedule,
                'shift' => $shift,
                'is_working' => $isWorking,
                'start' => $window['start'],
                'end' => $window['end'],
                'source' => $assignment->source,
            ];
        }

        return ['schedule' => $schedule, 'shift' => null, 'is_working' => $isWorking, 'start' => null, 'end' => null, 'source' => $assignment->source];
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public function windowFor(HrShift $shift, Carbon $day): array
    {
        $start = $day->copy()->setTimeFromTimeString((string) $shift->start_time);
        $end = $day->copy()->setTimeFromTimeString((string) $shift->end_time);

        if ((bool) $shift->cross_midnight || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return ['start' => $start, 'end' => $end];
    }

    /** @return list<array<string, mixed>> */
    public function roster(string $from, string $to, ?int $organizationId = null, ?int $departmentId = null): array
    {
        $employees = HrEmployee::query()
            ->where('lifecycle_state', 'active')
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->limit(500)
            ->get();

        $fromDate = Carbon::parse($from);
        $toDate = Carbon::parse($to);
        $rows = [];

        foreach ($employees as $employee) {
            for ($date = $fromDate->copy(); $date->lessThanOrEqualTo($toDate); $date->addDay()) {
                $resolved = $this->resolveFor($employee->getKey(), $date);

                if (! $resolved['is_working']) {
                    continue;
                }

                $rows[] = [
                    'employee_id' => $employee->getKey(),
                    'employee_name' => $employee->displayName() ?? $employee->employee_number,
                    'date' => $date->toDateString(),
                    'shift' => $resolved['shift']?->code,
                    'start' => $resolved['start']?->toDateTimeString(),
                    'end' => $resolved['end']?->toDateTimeString(),
                ];
            }
        }

        return $rows;
    }

    private function isCrossMidnight(string $start, string $end): bool
    {
        return Carbon::parse($end)->lessThanOrEqualTo(Carbon::parse($start));
    }

    private function hoursBetween(string $start, string $end, bool $crossMidnight, int $breakMinutes = 0): float
    {
        $from = Carbon::parse($start);
        $to = Carbon::parse($end);

        if ($crossMidnight || $to->lessThanOrEqualTo($from)) {
            $to->addDay();
        }

        return round(max(0, $from->diffInMinutes($to) - $breakMinutes) / 60, 2);
    }
}
