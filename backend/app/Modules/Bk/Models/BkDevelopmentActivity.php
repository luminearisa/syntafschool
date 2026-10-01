<?php

namespace App\Modules\Bk\Models;

use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kegiatan pengembangan yang menopang satu sasaran.
 *
 * Kegiatan yang tidak terlaksana (MISSED) dibedakan dari yang dibatalkan
 * (CANCELLED), karena keduanya bermakna berbeda saat meninjau kemajuan.
 *
 * Tabel: `bk_development_activities`
 */
class BkDevelopmentActivity extends BkModel
{
    protected $table = 'bk_development_activities';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'scheduled_date' => 'date',
        'completed_date' => 'date',
        'status' => \App\Modules\Bk\Domain\Enums\DevelopmentActivityStatus::class,
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BkDevelopmentPlan::class, 'plan_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(BkDevelopmentGoal::class, 'goal_id');
    }

    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['PLANNED', 'SCHEDULED', 'IN_PROGRESS']);
    }
}
