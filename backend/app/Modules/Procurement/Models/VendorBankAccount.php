<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rekening bank vendor. Tabel: `proc_vendor_bank_accounts`. */
class VendorBankAccount extends ProcurementModel
{
    protected $table = 'proc_vendor_bank_accounts';

    protected $casts = [
        'is_primary' => 'boolean',
        'is_verified' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
