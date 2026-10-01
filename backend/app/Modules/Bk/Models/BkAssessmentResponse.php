<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Jawaban satu pertanyaan asesmen.
 *
 * Tabel: `bk_assessment_responses`
 */
class BkAssessmentResponse extends BkModel
{
    protected $table = 'bk_assessment_responses';

    protected $casts = [
        'value_boolean' => 'boolean',
        'value_date' => 'date',
        'value_options' => 'array',
        'responded_at' => 'datetime',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(BkAssessment::class, 'assessment_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(BkAssessmentQuestion::class, 'question_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
