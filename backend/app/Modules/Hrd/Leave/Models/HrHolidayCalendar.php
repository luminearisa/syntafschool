<?php

namespace App\Modules\Hrd\Leave\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Leave\Models\HrHoliday;

/**
 * Phase 2 — tabel `hr_holiday_calendars` (domain Leave).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrHolidayCalendar extends HrdModel
{
    protected $table = 'hr_holiday_calendars';

    protected $guarded = [];
    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function holidays(): HasMany
    {
        return $this->hasMany(HrHoliday::class, 'calendar_id');
    }
}
