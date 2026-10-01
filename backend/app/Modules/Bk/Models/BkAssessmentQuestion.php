<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\AssessmentQuestionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pertanyaan asesmen beserta jenis jawabannya.
 *
 * Tabel: `bk_assessment_questions`
 */
class BkAssessmentQuestion extends BkModel
{
    protected $table = 'bk_assessment_questions';

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_sensitive' => 'boolean',
        'is_scored' => 'boolean',
        'question_type' => \App\Modules\Bk\Domain\Enums\AssessmentQuestionType::class,
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(BkAssessmentTemplate::class, 'template_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(BkAssessmentSection::class, 'section_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(BkAssessmentResponse::class, 'question_id');
    }
}
