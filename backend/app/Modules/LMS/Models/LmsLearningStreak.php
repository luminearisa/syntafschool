<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rentetan hari belajar (§30). Definisi "hari aktif" dapat dikonfigurasi dan
 * default-nya adalah minimal satu aktivitas belajar diselesaikan.
 */
class LmsLearningStreak extends BaseModel
{
    use ResolvesIdentity;

    protected $table = 'lms_learning_streaks';

    protected $casts = [
        'current_streak' => 'integer',
        'longest_streak' => 'integer',
        'total_active_days' => 'integer',
        'last_activity_date' => 'date:Y-m-d',
        'streak_started_on' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }
}
