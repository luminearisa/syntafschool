<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sesi coaching (PMM: diskusi persiapan/tindak lanjut) beserta komitmen. */
class SupCoachingSession extends BaseModel
{
    protected $table = 'sup_coaching_sessions';

    protected $casts = [
        'session_at' => 'datetime',
        'follow_up_at' => 'datetime',
        'commitments' => 'array',
    ];

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id');
    }

    public function supervisee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisee_employee_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }
}
