<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\BadgeRuleType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsBadgeRule extends BaseModel
{
    protected $table = 'lms_badge_rules';

    protected $casts = [
        'type' => BadgeRuleType::class,
        'threshold' => 'decimal:2',
        'count' => 'integer',
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    public function badge(): BelongsTo
    {
        return $this->belongsTo(LmsBadge::class, 'badge_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }
}
