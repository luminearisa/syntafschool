<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kaitan satu kreator (person) dengan satu bibliographic record, beserta
 * perannya (Penulis/Editor/Penerjemah/…) dan urutan tampilnya.
 *
 * Peran melekat pada kaitan ini — bukan pada tabel orang — sehingga satu
 * orang dapat berperan berbeda pada karya yang berbeda.
 */
class LibraryBibliographyCreator extends BaseModel
{
    protected $table = 'library_bibliography_creators';

    protected $casts = [
        'sequence' => 'integer',
        'is_primary' => 'boolean',
    ];

    public function bibliography(): BelongsTo
    {
        return $this->belongsTo(LibraryBibliography::class, 'bibliography_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'creator_id');
    }

    public function creatorRole(): BelongsTo
    {
        return $this->belongsTo(LibraryCreatorRole::class, 'creator_role_id');
    }
}