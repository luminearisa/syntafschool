<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Series/terbitan berseri (spesifikasi §20).
 *
 * Satu series dapat menampung banyak bibliographic record pada Step 3.
 */
class LibrarySeries extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = ['sort_order' => 'integer'];

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }
}