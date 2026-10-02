<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Departemen (§6). Selalu milik satu unit organisasi; departemen lintas unit
 * ditolak di lapisan layanan supaya cakupan data tetap dapat ditegakkan.
 */
class HrDepartment extends HrdModel
{
    use HasFactory;
    use SoftDeletes;

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'version' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'head_employee_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(HrWorkLocation::class, 'location_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(HrPosition::class, 'department_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(HrEmployee::class, 'department_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
