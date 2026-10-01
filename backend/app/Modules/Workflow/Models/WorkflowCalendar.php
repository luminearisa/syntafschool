<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kalender kerja untuk perhitungan SLA (§70).
 */
class WorkflowCalendar extends WorkflowModel
{
    protected $table = 'workflow_calendars';

    protected $casts = [
        'work_days' => 'array',
        'settings' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function holidays(): HasMany
    {
        return $this->hasMany(WorkflowCalendarHoliday::class, 'calendar_id');
    }

    /** @return list<int> hari kerja (ISO-8601: 1=Senin ... 7=Minggu) */
    public function workDays(): array
    {
        $days = $this->work_days;

        if (! is_array($days) || $days === []) {
            return (array) config('workflow.business_calendar.weekend', [6, 7]) === []
                ? [1, 2, 3, 4, 5, 6, 7]
                : [1, 2, 3, 4, 5];
        }

        return array_map('intval', $days);
    }

    public function workStart(): string
    {
        return (string) ($this->work_start ?: config('workflow.business_calendar.work_start', '08:00'));
    }

    public function workEnd(): string
    {
        return (string) ($this->work_end ?: config('workflow.business_calendar.work_end', '17:00'));
    }
}
