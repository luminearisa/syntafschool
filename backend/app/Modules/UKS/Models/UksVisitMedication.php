<?php

namespace App\Modules\UKS\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UksVisitMedication extends BaseModel
{
    protected $table = 'uks_visit_medications';

    protected $casts = [
        'quantity' => 'decimal:2',
        'given_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(UksVisit::class, 'visit_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(UksMedicine::class, 'medicine_id');
    }

    public function givenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }
}
