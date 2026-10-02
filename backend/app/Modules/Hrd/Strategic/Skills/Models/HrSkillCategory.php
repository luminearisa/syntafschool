<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillDomain;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSkillCategory extends Model
{
    protected $table = 'hr_skill_categories';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'domain_id' => 'integer',
        'description' => 'string',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(HrSkillDomain::class, 'domain_id');
    }
}
