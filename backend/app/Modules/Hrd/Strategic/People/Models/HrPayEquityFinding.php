<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Strategic\People\Models\HrPayEquityAnalysis;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPayEquityFinding extends Model
{
    protected $table = 'hr_pay_equity_findings';

    protected $guarded = [];

    protected $casts = [
        'analysis_id' => 'integer',
        'employee_id' => 'integer',
        'anomaly_type' => 'string',
        'position_id' => 'integer',
        'grade_code' => 'string',
        'comparison_group' => 'string',
        'expected_min' => 'decimal:2',
        'expected_max' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'variance_percent' => 'decimal:2',
        'evidence' => 'array',
        'status' => 'string',
        'explanation' => 'string',
        'reviewed_by' => 'integer',
        'meta' => 'array',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(HrPayEquityAnalysis::class, 'analysis_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }
}
