<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kontak vendor. Tabel: `proc_vendor_contacts`. */
class VendorContact extends ProcurementModel
{
    protected $table = 'proc_vendor_contacts';

    protected $casts = ['is_primary' => 'boolean'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
