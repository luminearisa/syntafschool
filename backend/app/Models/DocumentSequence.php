<?php

namespace App\Models;

/**
 * Penomoran dokumen (sistem penomoran existing).
 *
 * `format` adalah data dan boleh memuat token: `{SEQ}`, `{SEQ:n}` (padding),
 * `{YYYY}`, `{YY}`, `{MM}`. Dikelola modul PPDB lewat `PpdbNumberingService`;
 * tabel ini sebelumnya belum dipakai modul lain.
 */
class DocumentSequence extends BaseModel
{
    protected $casts = [
        'last_number' => 'integer',
        'last_reset_at' => 'datetime',
    ];
}