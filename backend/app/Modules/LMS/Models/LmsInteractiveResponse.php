<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil pengerjaan konten interaktif (§8–§12).
 */
class LmsInteractiveResponse extends BaseModel
{
    use ResolvesIdentity;

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_COMPLETED = 'COMPLETED';

    protected $table = 'lms_interactive_responses';

    protected $casts = [
        'attempt_number' => 'integer',
        'interactions_seen' => 'integer',
        'interactions_required' => 'integer',
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'percentage' => 'decimal:2',
        'passed' => 'boolean',
        'slides_seen' => 'integer',
        'cards_known' => 'integer',
        'state' => 'array',
        'responses' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(LmsInteractiveContent::class, 'interactive_content_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
