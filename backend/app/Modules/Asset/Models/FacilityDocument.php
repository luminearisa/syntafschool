<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `facility_documents` — modul Asset & Sarpras.
 */
class FacilityDocument extends Model
{
    use BelongsToSchool;

    protected $table = 'facility_documents';

    protected $fillable = [
        'facility_id',
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
}
