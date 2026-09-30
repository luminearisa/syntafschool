<?php

namespace App\Modules\UKS\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\UKS\Domain\Enums\StockDirection;
use App\Modules\UKS\Domain\Enums\StockReason;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UksStockMovement extends BaseModel
{
    protected $table = 'uks_stock_movements';

    protected $casts = [
        'quantity' => 'decimal:2',
        'stock_after' => 'decimal:2',
        'direction' => StockDirection::class,
        'reason' => StockReason::class,
    ];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(UksMedicine::class, 'medicine_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(UksVisit::class, 'visit_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
