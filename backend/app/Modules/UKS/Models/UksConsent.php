<?php

namespace App\Modules\UKS\Models;

use App\Models\BaseModel;
use App\Models\Guardian;
use App\Models\User;
use App\Modules\UKS\Domain\Enums\ConsentDecision;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UksConsent extends BaseModel
{
    protected $table = 'uks_consents';

    protected $casts = [
        'decided_at' => 'datetime',
        'decision' => ConsentDecision::class,
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(UksVisit::class, 'visit_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
