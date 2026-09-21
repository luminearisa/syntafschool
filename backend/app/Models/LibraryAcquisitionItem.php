<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris judul pada satu permintaan pengadaan.
 */
class LibraryAcquisitionItem extends BaseModel
{
    protected $casts = [
        'quantity' => 'integer',
        'received_quantity' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(LibraryAcquisitionRequest::class, 'request_id');
    }

    public function bibliography(): BelongsTo
    {
        return $this->belongsTo(LibraryBibliography::class, 'bibliography_id');
    }

    public function collectionType(): BelongsTo
    {
        return $this->belongsTo(LibraryCollectionType::class, 'collection_type_id');
    }

    public function subtotal(): float
    {
        return round((float) $this->unit_price * (int) $this->quantity, 2);
    }
}