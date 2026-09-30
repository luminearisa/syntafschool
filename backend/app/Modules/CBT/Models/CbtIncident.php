<?php

namespace App\Modules\CBT\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtIncident extends CbtModel
{
    protected $table = 'cbt_incidents';

    protected $casts = [
        'meta' => 'array',
        'severity' => 'integer',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CbtAttempt::class, 'attempt_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'session_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
