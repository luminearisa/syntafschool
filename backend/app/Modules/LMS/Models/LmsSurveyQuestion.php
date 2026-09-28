<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\SurveyQuestionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pertanyaan survei (§39). */
class LmsSurveyQuestion extends BaseModel
{
    protected $table = 'lms_survey_questions';

    protected $casts = [
        'question_type' => SurveyQuestionType::class,
        'options' => 'array',
        'rating_min' => 'integer',
        'rating_max' => 'integer',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(LmsSurvey::class, 'survey_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LmsSurveyResponse::class, 'question_id');
    }
}
