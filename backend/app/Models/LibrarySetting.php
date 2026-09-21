<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Konfigurasi key-value per perpustakaan.
 *
 * Nilai disimpan sebagai teks dan di-cast sesuai `type`, sehingga aturan
 * operasional (durasi pinjam, batas perpanjangan, RFID, dsb.) adalah DATA —
 * bukan puluhan kolom boolean.
 */
class LibrarySetting extends BaseModel
{
    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_DECIMAL = 'decimal';
    public const TYPE_JSON = 'json';

    public const TYPES = [
        self::TYPE_STRING,
        self::TYPE_INTEGER,
        self::TYPE_BOOLEAN,
        self::TYPE_DECIMAL,
        self::TYPE_JSON,
    ];

    public const GROUP_GENERAL = 'general';
    public const GROUP_LOAN = 'loan';
    public const GROUP_FINE = 'fine';
    public const GROUP_MEMBER = 'member';
    public const GROUP_CATALOG = 'catalog';
    public const GROUP_RESERVATION = 'reservation';
    public const GROUP_OPAC = 'opac';

    public const GROUPS = [
        self::GROUP_GENERAL,
        self::GROUP_LOAN,
        self::GROUP_FINE,
        self::GROUP_MEMBER,
        self::GROUP_CATALOG,
        self::GROUP_RESERVATION,
        self::GROUP_OPAC,
    ];

    /** Definisi bawaan: key, tipe, grup, nilai default, deskripsi. */
    public const DEFAULTS = [
        // Circulation — hanya fondasi konfigurasi, workflow belum dibuat.
        'loan.default_duration' => [self::TYPE_INTEGER, self::GROUP_LOAN, 7, 'Durasi pinjam bawaan (hari)'],
        'loan.max_items' => [self::TYPE_INTEGER, self::GROUP_LOAN, 3, 'Jumlah maksimum koleksi yang dapat dipinjam'],
        'loan.renewal_limit' => [self::TYPE_INTEGER, self::GROUP_LOAN, 1, 'Batas perpanjangan per pinjaman'],
        'fine.enabled' => [self::TYPE_BOOLEAN, self::GROUP_FINE, false, 'Aktifkan denda keterlambatan'],
        'fine.per_day' => [self::TYPE_DECIMAL, self::GROUP_FINE, 0, 'Denda per hari keterlambatan'],
        'reservation.enabled' => [self::TYPE_BOOLEAN, self::GROUP_RESERVATION, true, 'Aktifkan reservasi koleksi'],
        // Fase 4 — sirkulasi.
        'loan.block_on_outstanding_fine' => [self::TYPE_BOOLEAN, self::GROUP_LOAN, false, 'Blokir peminjaman bila anggota punya denda belum dibayar'],
        'loan.reminder_days_before' => [self::TYPE_INTEGER, self::GROUP_LOAN, 2, 'Kirim pengingat H- berapa sebelum jatuh tempo'],
        'reservation.hold_days' => [self::TYPE_INTEGER, self::GROUP_RESERVATION, 2, 'Berapa hari eksemplar ditahan setelah tersedia'],
        // Membership
        'member.membership_enabled' => [self::TYPE_BOOLEAN, self::GROUP_MEMBER, true, 'Aktifkan keanggotaan'],
        'member.numbering_key' => [self::TYPE_STRING, self::GROUP_MEMBER, 'member', 'Kunci penomoran anggota'],
        'member.card_enabled' => [self::TYPE_BOOLEAN, self::GROUP_MEMBER, false, 'Aktifkan kartu anggota'],
        // Catalog
        'catalog.isbn_enabled' => [self::TYPE_BOOLEAN, self::GROUP_CATALOG, true, 'Aktifkan ISBN'],
        'catalog.barcode_enabled' => [self::TYPE_BOOLEAN, self::GROUP_CATALOG, true, 'Aktifkan barcode'],
        'catalog.rfid_enabled' => [self::TYPE_BOOLEAN, self::GROUP_CATALOG, false, 'Aktifkan RFID (belum diimplementasikan)'],
        // Pola call number dapat diubah admin; token: {CLASS} {AUTHOR} {YEAR} {TITLE}.
        'catalog.call_number_pattern' => [self::TYPE_STRING, self::GROUP_CATALOG, '{CLASS} {AUTHOR} {YEAR}', 'Pola penyusunan call number'],
        // Barcode eksemplar dibuat otomatis dari item code? Jika tidak, diisi manual.
        'catalog.barcode_auto' => [self::TYPE_BOOLEAN, self::GROUP_CATALOG, true, 'Barcode eksemplar dibuat otomatis'],
        // OPAC
        'opac.enabled' => [self::TYPE_BOOLEAN, self::GROUP_OPAC, true, 'Aktifkan katalog publik (OPAC)'],
        'opac.show_location' => [self::TYPE_BOOLEAN, self::GROUP_OPAC, true, 'Tampilkan lokasi koleksi di OPAC'],
        'opac.show_call_number' => [self::TYPE_BOOLEAN, self::GROUP_OPAC, true, 'Tampilkan call number di OPAC'],
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    /** Nilai yang sudah di-cast sesuai `type`. */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            self::TYPE_INTEGER => (int) $this->value,
            self::TYPE_BOOLEAN => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_DECIMAL => (float) $this->value,
            self::TYPE_JSON => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}