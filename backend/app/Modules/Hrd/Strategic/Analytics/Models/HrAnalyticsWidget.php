<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Analytics\Models\HrAnalyticsDashboard;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAnalyticsWidget extends Model
{
    protected $table = 'hr_analytics_widgets';

    protected $guarded = [];

    protected $casts = [
        'dashboard_id' => 'integer',
        'code' => 'string',
        'title' => 'string',
        'widget_type' => 'string',
        'metric_code' => 'string',
        'dimensions' => 'array',
        'query_plan' => 'array',
        'position' => 'integer',
        'size' => 'string',
        'filters' => 'array',
        'meta' => 'array',
    ];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(HrAnalyticsDashboard::class, 'dashboard_id');
    }
}
