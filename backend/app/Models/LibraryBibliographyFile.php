<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Berkas media sebuah bibliographic record (cover/thumbnail/back/additional).
 *
 * Hanya menyimpan PATH — tidak ada binary di basis data. Fase 3 tidak
 * membangun sistem upload; bila media system tersedia, cukup mengisi kolom ini.
 */
class LibraryBibliographyFile extends BaseModel
{
    protected $table = 'library_bibliography_files';

    protected $casts = ['sort_order' => 'integer'];

    public function bibliography(): BelongsTo
    {
        return $this->belongsTo(LibraryBibliography::class, 'bibliography_id');
    }
}