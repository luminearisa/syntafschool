<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jawaban survei (§39).
 *
 * Pada survei anonim `user_id` sengaja dikosongkan agar hasil benar-benar tidak
 * dapat ditelusuri ke siswa — pengelompokan satu pengisian memakai
 * `submission_key` acak.
 */
class LmsSurveyResponse extends BaseModel
{
    use ResolvesIdentity;

    protected $table = 'lms_survey_responses';

    protected $casts = [
        'response_data' => 'array',
        'response_value' => 'decimal:2',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(LmsSurvey::class, 'survey_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsSurveyQuestion::class, 'question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }
}
