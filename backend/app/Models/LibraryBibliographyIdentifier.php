<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identifier bibliografi (ISBN-10/13, ISSN, DOI, OCLC, lokal, lain).
 *
 * Dibuat terpisah karena satu record dapat memiliki lebih dari satu
 * identifier — bukan satu kolom `isbn` (§14). `normalized_value` dipakai
 * untuk pencarian agar konsisten (tanpa tanda hubung/spasi).
 */
class LibraryBibliographyIdentifier extends BaseModel
{
    protected $table = 'library_bibliography_identifiers';

    protected $casts = ['is_primary' => 'boolean'];

    public function bibliography(): BelongsTo
    {
        return $this->belongsTo(LibraryBibliography::class, 'bibliography_id');
    }

    /** Normalisasi nilai identifier: buang semua selain huruf/angka. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $value);

        return $normalized === '' ? null : mb_strtoupper($normalized);
    }
}