<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\BadgeRuleType;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Badge (§26).
 */
class LmsBadge extends BaseModel
{
    use LogsLmsActivity, SoftDeletes;

    protected $table = 'lms_badges';

    protected $casts = [
        'rule_type' => BadgeRuleType::class,
        'rule_config' => 'array',
        'points' => 'integer',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(LmsBadgeRule::class, 'badge_id');
    }

    public function awards(): HasMany
    {
        return $this->hasMany(LmsBadgeAward::class, 'badge_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_BADGE';
    }
}
