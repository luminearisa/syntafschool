<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_activity_assessments`.
 */
class ReligiousActivityAssessment extends Model
{
    protected $table = 'religious_activity_assessments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'activity_id' => 'integer',
        'session_id' => 'integer',
        'participant_id' => 'integer',
        'assessment_type_id' => 'integer',
        'student_id' => 'integer',
        'assessor_id' => 'integer',
        'assessment_date' => 'date',
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'components' => 'array',
        'evidence_document_id' => 'integer',
        'meta' => 'array',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivity::class, 'activity_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivitySession::class, 'session_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivityParticipant::class, 'participant_id');
    }

    public function assessmentType(): BelongsTo
    {
        return $this->belongsTo(ReligiousAssessmentType::class, 'assessment_type_id');
    }
}
