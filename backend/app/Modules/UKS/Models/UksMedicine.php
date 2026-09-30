<?php

namespace App\Modules\UKS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UksMedicine extends BaseModel
{
    protected $table = 'uks_medicines';

    protected $casts = [
        'stock' => 'decimal:2',
        'min_stock' => 'decimal:2',
        'expiry_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(UksStockMovement::class, 'medicine_id');
    }

    public function isLowStock(): bool
    {
        return (float) $this->stock <= (float) $this->min_stock;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }
}
