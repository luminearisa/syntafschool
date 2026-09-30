<?php

namespace App\Modules\CBT\Models;

use App\Models\Student;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CbtAttempt extends CbtModel
{
    protected $table = 'cbt_attempts';

    protected $casts = [
        'started_at' => 'datetime',
        'deadline_at' => 'datetime',
        'submitted_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'percentage' => 'decimal:2',
        'passed' => 'boolean',
        'needs_manual_grade' => 'boolean',
        'question_order' => 'array',
        'option_orders' => 'array',
        'attempt_number' => 'integer',
        'integrity_score' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'session_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(CbtParticipant::class, 'participant_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CbtAnswer::class, 'attempt_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(CbtIncident::class, 'attempt_id');
    }
}
