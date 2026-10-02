<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_import_rows`.
 */
class ReligiousImportRow extends Model
{
    protected $table = 'religious_import_rows';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'batch_id' => 'integer',
        'row_number' => 'integer',
        'payload' => 'array',
        'errors' => 'array',
        'entity_id' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReligiousImportBatch::class, 'batch_id');
    }
}
