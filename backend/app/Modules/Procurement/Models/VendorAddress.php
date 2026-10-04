<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Alamat vendor (kantor/gudang/penagihan/pengiriman). Tabel: `proc_vendor_addresses`. */
class VendorAddress extends ProcurementModel
{
    protected $table = 'proc_vendor_addresses';

    protected $casts = ['is_default' => 'boolean'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
