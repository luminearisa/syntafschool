<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat versi konten interaktif (§7) — agar perubahan guru tidak menghapus
 * jawaban siswa pada versi sebelumnya.
 */
class LmsInteractiveContentVersion extends BaseModel
{
    protected $table = 'lms_interactive_content_versions';

    protected $casts = [
        'version' => 'integer',
        'content_data' => 'array',
        'configuration' => 'array',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(LmsInteractiveContent::class, 'interactive_content_id');
    }
}
