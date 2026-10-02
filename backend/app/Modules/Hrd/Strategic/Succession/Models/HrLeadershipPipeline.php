<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Succession\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrLeadershipPipeline extends Model
{
    protected $table = 'hr_leadership_pipeline';

    protected $guarded = [];

    protected $casts = [
        'employee_id' => 'integer',
        'stage' => 'string',
        'stage_since' => 'date:Y-m-d',
        'source' => 'string',
        'development_actions' => 'array',
        'sponsor_employee_id' => 'integer',
        'readiness_for_next' => 'string',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'sponsor_employee_id');
    }
}
