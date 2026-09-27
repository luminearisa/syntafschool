<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris paket tagihan (§12): satu jenis tagihan + nominal + frekuensi.
 */
class FinanceFeeItem extends BaseModel
{
    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(FinanceFeePackage::class, 'fee_package_id');
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FinanceFeeType::class, 'fee_type_id');
    }
}
