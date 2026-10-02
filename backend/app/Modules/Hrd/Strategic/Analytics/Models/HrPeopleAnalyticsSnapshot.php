<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPeopleAnalyticsSnapshot extends Model
{
    protected $table = 'hr_people_analytics_snapshots';

    protected $guarded = [];

    protected $casts = [
        'snapshot_date' => 'date:Y-m-d',
        'granularity' => 'string',
        'scope' => 'string',
        'organization_id' => 'integer',
        'domain' => 'string',
        'metrics' => 'array',
        'insights' => 'array',
        'computed_at' => 'datetime',
        'refresh_policy' => 'string',
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }
}
