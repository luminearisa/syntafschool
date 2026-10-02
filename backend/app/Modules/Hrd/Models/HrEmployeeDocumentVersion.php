<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versi berkas dokumen (§32). Satu baris per unggahan; `is_current` menandai
 * versi yang berlaku. `checksum` membuat perubahan berkas di luar aplikasi
 * terdeteksi, dan `reason` menjelaskan mengapa versi baru dibuat.
 */
class HrEmployeeDocumentVersion extends HrdModel
{
    protected $casts = [
        'version' => 'integer',
        'size_bytes' => 'integer',
        'is_current' => 'boolean',
        'uploaded_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeDocument::class, 'document_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function humanSize(): string
    {
        $bytes = (int) ($this->size_bytes ?? 0);

        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024, 1).' KB',
            default => $bytes.' B',
        };
    }
}
