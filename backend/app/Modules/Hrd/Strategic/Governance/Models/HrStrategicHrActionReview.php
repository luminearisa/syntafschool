<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrStrategicHrActionReview extends Model
{
    protected $table = 'hr_strategic_hr_action_reviews';

    protected $guarded = [];

    protected $casts = [
        'action_id' => 'integer',
        'review_date' => 'date:Y-m-d',
        'reviewer_employee_id' => 'integer',
        'progress_note' => 'string',
        'blockers' => 'array',
        'decisions' => 'array',
        'next_review_date' => 'date:Y-m-d',
        'status' => 'string',
        'meta' => 'array',
    ];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'reviewer_employee_id');
    }
}
