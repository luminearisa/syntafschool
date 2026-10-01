<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hari libur (atau hari kerja pengganti) pada sebuah kalender (§70).
 */
class WorkflowCalendarHoliday extends WorkflowModel
{
    protected $table = 'workflow_calendar_holidays';

    protected $casts = [
        'date' => 'date',
        'is_working_day' => 'boolean',
    ];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkflowCalendar::class, 'calendar_id');
    }
}
