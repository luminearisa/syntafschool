<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lampiran penawaran. Tabel: `proc_quotation_documents`. */
class QuotationDocument extends ProcurementModel
{
    protected $table = 'proc_quotation_documents';

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }
}
