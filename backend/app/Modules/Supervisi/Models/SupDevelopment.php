<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kegiatan pengembangan kompetensi (diklat, workshop, coaching, lesson study). */
class SupDevelopment extends BaseModel
{
    protected $table = 'sup_developments';

    protected $casts = [
        'hours' => 'float',
        'cost' => 'float',
        'started_at' => 'date',
        'finished_at' => 'date',
    ];

    public function supervisee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisee_employee_id');
    }

    public function linkedVisit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'linked_visit_id');
    }
}
