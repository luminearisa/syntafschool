<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\InspectionStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hasil inspeksi mutu barang. Tabel: `proc_goods_receipt_inspections`. */
class GoodsReceiptInspection extends ProcurementModel
{
    protected $table = 'proc_goods_receipt_inspections';

    protected $casts = [
        'checklist' => 'array',
        'inspected_at' => 'datetime',
        'status' => InspectionStatus::class,
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class, 'goods_receipt_item_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
