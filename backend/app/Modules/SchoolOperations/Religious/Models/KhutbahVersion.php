<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `khutbah_versions`.
 */
class KhutbahVersion extends Model
{
    protected $table = 'khutbah_versions';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    /** Tabel append-only: hanya `created_at`, tanpa `updated_at`. */
    public $timestamps = false;

    protected $casts = [
        'khutbah_id' => 'integer',
        'version_number' => 'integer',
        'document_id' => 'integer',
        'changed_by' => 'integer',
        'created_at' => 'datetime',
    ];

    public function khutbah(): BelongsTo
    {
        return $this->belongsTo(Khutbah::class, 'khutbah_id');
    }
}
