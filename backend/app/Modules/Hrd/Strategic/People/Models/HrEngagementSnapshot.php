<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrEngagementSnapshot extends Model
{
    protected $table = 'hr_engagement_snapshots';

    protected $guarded = [];

    protected $casts = [
        'period' => 'string',
        'scope' => 'string',
        'organization_id' => 'integer',
        'metric' => 'string',
        'value' => 'decimal:2',
        'respondents' => 'integer',
        'privacy_threshold' => 'integer',
        'source' => 'string',
        'trend_ref' => 'array',
        'generated_at' => 'datetime',
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }
}
