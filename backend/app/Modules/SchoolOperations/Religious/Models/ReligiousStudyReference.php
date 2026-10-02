<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `religious_study_references`.
 */
class ReligiousStudyReference extends Model
{
    protected $table = 'religious_study_references';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'library_item_id' => 'integer',
        'publication_year' => 'integer',
        'file_document_id' => 'integer',
        'tags' => 'array',
        'active' => 'boolean',
        'meta' => 'array',
    ];

    public function religiousStudySessions(): HasMany
    {
        return $this->hasMany(ReligiousStudySession::class, 'reference_id');
    }
}
