<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isi sebuah learning item RESOURCE (§13).
 *
 * Berkas tidak pernah disimpan di database: hanya path pada disk Laravel +
 * metadata untuk otorisasi unduhan.
 */
class LmsResource extends BaseModel
{
    protected $table = 'lms_resources';

    protected $casts = [
        'settings' => 'array',
        'file_size' => 'integer',
        'duration_seconds' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'learning_item_id');
    }

    public function isExternal(): bool
    {
        return $this->external_url !== null && $this->file_path === null;
    }

    public function isEmbed(): bool
    {
        return $this->open_in === 'EMBED';
    }
}
