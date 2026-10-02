<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Analytics\Models\HrAnalyticsWidget;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAnalyticsDashboard extends Model
{
    protected $table = 'hr_analytics_dashboards';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'audience' => 'string',
        'scope_type' => 'string',
        'default_filters' => 'array',
        'status' => 'string',
        'owner_user_id' => 'integer',
        'layout' => 'array',
        'meta' => 'array',
    ];

    public function widgets(): HasMany
    {
        return $this->hasMany(HrAnalyticsWidget::class, 'dashboard_id');
    }
}
