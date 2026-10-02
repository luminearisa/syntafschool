<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Governance\Models\HrRisk;
use App\Modules\Hrd\Strategic\Governance\Models\HrRiskSignal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrRiskEvidence extends Model
{
    protected $table = 'hr_risk_evidence';

    protected $guarded = [];

    protected $casts = [
        'risk_id' => 'integer',
        'signal_id' => 'integer',
        'evidence_type' => 'string',
        'reference' => 'string',
        'value' => 'decimal:2',
        'period' => 'string',
        'payload' => 'array',
        'collected_at' => 'datetime',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(HrRisk::class, 'risk_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(HrRiskSignal::class, 'signal_id');
    }
}
