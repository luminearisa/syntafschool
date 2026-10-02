<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrOrganizationDesignScenario extends Model
{
    protected $table = 'hr_organization_design_scenarios';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'description' => 'string',
        'base_organization_id' => 'integer',
        'proposal' => 'array',
        'status' => 'string',
        'impact_headcount' => 'decimal:2',
        'impact_cost' => 'decimal:2',
        'impact_layers' => 'integer',
        'impact_span_of_control' => 'decimal:2',
        'proposed_by' => 'integer',
        'simulated_at' => 'datetime',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function baseOrganization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'base_organization_id');
    }
}
