<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Konfigurasi penomoran per perpustakaan (kunci: member, loan, item, dst.).
 *
 * Format TIDAK ditulis keras di kode; token yang didukung:
 * {SEQ}, {YYYY}, {YY}, {MM}, {LIBRARY_CODE}. Lihat NumberingService.
 *
 * Dipisah dari `document_sequences` yang sudah ada karena tabel itu global
 * (kode unik lintas sistem, tanpa padding/nomor awal) sehingga dua
 * perpustakaan akan saling menabrak nomor.
 */
class LibraryNumberingConfig extends BaseModel
{
    use LogsLibraryActivity;

    public const RESET_NEVER = 'never';
    public const RESET_YEAR = 'year';
    public const RESET_MONTH = 'month';

    public const RESET_PERIODS = [self::RESET_NEVER, self::RESET_YEAR, self::RESET_MONTH];

    /** Kunci penomoran yang disiapkan (entity menyusul per fase). */
    public const KEYS = [
        'bibliography' => 'Bibliografi',
        'member' => 'Anggota',
        'member_card' => 'Kartu Anggota',
        'book' => 'Buku',
        'item' => 'Eksemplar',
        'accession' => 'Nomor Inventaris',
        'loan' => 'Peminjaman',
        'reservation' => 'Reservasi',
        'purchase' => 'Pengadaan',
        'inventory' => 'Stock Opname',
        'certificate' => 'Sertifikat',
    ];

    /** Format bawaan per kunci — selalu dapat diubah admin (jangan hardcode). */
    public const DEFAULT_FORMATS = [
        'bibliography' => 'BIB-{YYYY}-{SEQ}',
        'member' => 'LIB-MBR-{YYYY}-{SEQ}',
        'member_card' => 'CARD-{YYYY}-{SEQ}',
        'book' => 'LIB-BK-{SEQ}',
        'item' => 'LIB-{LIBRARY_CODE}-{SEQ}',
        // Nomor inventaris dipisahkan dari item code (§21).
        'accession' => 'ACQ-{YYYY}-{SEQ}',
        'loan' => 'LOAN-{YYYY}-{SEQ}',
        'reservation' => 'RSV-{YYYY}-{SEQ}',
        'purchase' => 'PO-{YYYY}-{SEQ}',
        'inventory' => 'OPN-{YYYY}-{SEQ}',
        'certificate' => 'CERT-{YYYY}-{SEQ}',
    ];

    protected $casts = [
        'padding' => 'integer',
        'start_number' => 'integer',
        'last_number' => 'integer',
        'last_reset_at' => 'datetime',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function activityEventPrefix(): string
    {
        return 'LIBRARY_NUMBERING';
    }
}