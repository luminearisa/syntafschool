<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Governance\Models\HrRisk;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrRiskHistory extends Model
{
    protected $table = 'hr_risk_history';

    protected $guarded = [];

    protected $casts = [
        'risk_id' => 'integer',
        'from_status' => 'string',
        'to_status' => 'string',
        'from_severity' => 'string',
        'to_severity' => 'string',
        'changed_by' => 'integer',
        'notes' => 'string',
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(HrRisk::class, 'risk_id');
    }
}
