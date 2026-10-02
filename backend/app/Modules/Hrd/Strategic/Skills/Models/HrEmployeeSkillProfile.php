<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrEmployeeSkillProfile extends Model
{
    protected $table = 'hr_employee_skill_profiles';

    protected $guarded = [];

    protected $casts = [
        'employee_id' => 'integer',
        'verified_skill_count' => 'integer',
        'evidence_count' => 'integer',
        'avg_confidence' => 'decimal:2',
        'critical_skill_coverage' => 'decimal:2',
        'top_skills' => 'array',
        'stale_skill_count' => 'integer',
        'emerging_skill_count' => 'integer',
        'last_refreshed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
