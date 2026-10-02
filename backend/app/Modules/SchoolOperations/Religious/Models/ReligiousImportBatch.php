<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `religious_import_batches`.
 */
class ReligiousImportBatch extends Model
{
    protected $table = 'religious_import_batches';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'invalid_rows' => 'integer',
        'imported_rows' => 'integer',
        'error_report' => 'array',
        'preview' => 'array',
        'imported_by' => 'integer',
        'committed_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function religiousImportRows(): HasMany
    {
        return $this->hasMany(ReligiousImportRow::class, 'batch_id');
    }
}
