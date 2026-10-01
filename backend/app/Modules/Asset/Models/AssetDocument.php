<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_documents` — modul Asset & Sarpras.
 */
class AssetDocument extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_documents';

    protected $fillable = [
        'asset_id',
        'document_type',
        'title',
        'reference',
        'original_name',
        'mime_type',
        'size_bytes',
        'is_private',
        'expires_at',
        'uploaded_by',
        'notes',
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'expires_at' => 'date',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
