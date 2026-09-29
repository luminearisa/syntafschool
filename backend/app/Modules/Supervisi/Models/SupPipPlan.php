<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Rencana Perbaikan Kinerja (PIP) untuk pegawai yang belum memenuhi standar. */
class SupPipPlan extends BaseModel
{
    use SoftDeletes;

    protected $table = 'sup_pip_plans';

    protected $casts = [
        'start_date' => 'date',
        'final_review_date' => 'date',
        'acknowledged_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SupPipItem::class, 'pip_plan_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(SupPipReview::class, 'pip_plan_id')->orderByDesc('reviewed_at');
    }
}
