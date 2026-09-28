<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Bank soal reusable (§14). */
class LmsQuestionBank extends BaseModel
{
    use LogsLmsActivity, ResolvesIdentity, SoftDeletes;

    protected $table = 'lms_question_banks';

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'created_by');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(LmsQuestionCategory::class, 'bank_id')->orderBy('sort_order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(LmsQuestion::class, 'bank_id');
    }
}
