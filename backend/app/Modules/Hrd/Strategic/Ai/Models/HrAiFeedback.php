<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRecommendation;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRun;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiFeedback extends Model
{
    protected $table = 'hr_ai_feedback';

    protected $guarded = [];

    protected $casts = [
        'run_id' => 'integer',
        'recommendation_id' => 'integer',
        'user_id' => 'integer',
        'feedback' => 'string',
        'comment' => 'string',
        'meta' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(HrAiRun::class, 'run_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(HrAiRecommendation::class, 'recommendation_id');
    }
}
