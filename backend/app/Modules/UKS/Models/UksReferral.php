<?php

namespace App\Modules\UKS\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\UKS\Domain\Enums\ReferralStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UksReferral extends BaseModel
{
    protected $table = 'uks_referrals';

    protected $casts = [
        'referred_at' => 'datetime',
        'returned_at' => 'datetime',
        'status' => ReferralStatus::class,
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(UksVisit::class, 'visit_id');
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }
}
