<?php

namespace App\Modules\CBT\Models;

use App\Models\Student;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CbtParticipant extends CbtModel
{
    protected $table = 'cbt_participants';

    protected $casts = [
        'extra_minutes' => 'integer',
        'checked_in_at' => 'datetime',
        'is_eligible' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CbtAttempt::class, 'participant_id');
    }
}
