<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Survei umpan balik pembelajaran (§39). */
class LmsSurvey extends BaseModel
{
    protected $table = 'lms_surveys';

    protected $casts = [
        'anonymous' => 'boolean',
        'allow_multiple_submissions' => 'boolean',
        'show_results_to_students' => 'boolean',
        'settings' => 'array',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(LmsSurveyQuestion::class, 'survey_id')->orderBy('sort_order');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LmsSurveyResponse::class, 'survey_id');
    }

    public function isAnonymous(): bool
    {
        return (bool) $this->anonymous;
    }
}
