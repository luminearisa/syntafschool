<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrStrategicHrActionItem extends Model
{
    protected $table = 'hr_strategic_hr_action_items';

    protected $guarded = [];

    protected $casts = [
        'action_id' => 'integer',
        'sequence' => 'integer',
        'title' => 'string',
        'item_type' => 'string',
        'owner_employee_id' => 'integer',
        'due_date' => 'date:Y-m-d',
        'status' => 'string',
        'progress_percent' => 'decimal:2',
        'depends_on_item_id' => 'integer',
        'workflow_reference' => 'string',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'owner_employee_id');
    }

    public function dependsOnItem(): BelongsTo
    {
        return $this->belongsTo(HrStrategicHrActionItem::class, 'depends_on_item_id');
    }
}
