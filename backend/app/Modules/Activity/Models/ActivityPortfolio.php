<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Potret portofolio aktivitas siswa pada satu periode (hasil perakitan ulang, bukan sumber data).
 *
 * Tabel: `activity_portfolios`
 */
class ActivityPortfolio extends ActivityModel
{
    protected $table = 'activity_portfolios';

    protected $casts = [
        'snapshot' => 'array',
        'generated_at' => 'datetime',
        'public_shared_at' => 'datetime',
        'public_share_enabled' => 'boolean',
    ];
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function period(): BelongsTo
    {
        return $this->belongsTo(ActivityPeriod::class, 'period_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
