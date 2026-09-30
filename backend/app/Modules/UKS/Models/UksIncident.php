<?php

namespace App\Modules\UKS\Models;

use App\Models\BaseModel;
use App\Models\Student;
use App\Models\User;
use App\Modules\UKS\Domain\Enums\IncidentStatus;
use App\Modules\UKS\Domain\Enums\Severity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UksIncident extends BaseModel
{
    protected $table = 'uks_incidents';

    protected $casts = [
        'occurred_at' => 'datetime',
        'parent_notified' => 'boolean',
        'severity' => Severity::class,
        'status' => IncidentStatus::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(UksVisit::class, 'visit_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
