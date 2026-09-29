<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Status sinkronisasi nilai ke SIAKAD (§41, §6).
 *
 * Phase 5 menambahkan status antrean (`PENDING`, `PROCESSING`, `RETRYING`,
 * `CANCELLED`) tanpa mengubah arti status lama — nilai yang sudah tersinkron
 * tetap `SYNCED` dan tetap dikunci setelah kirim.
 */
enum GradeSyncStatus: string
{
    case NOT_SYNCED = 'NOT_SYNCED';
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case SYNCING = 'SYNCING';
    case RETRYING = 'RETRYING';
    case SYNCED = 'SYNCED';
    case SYNC_FAILED = 'SYNC_FAILED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case SKIPPED = 'SKIPPED';

    public function label(): string
    {
        return match ($this) {
            self::NOT_SYNCED => 'Belum disinkronkan',
            self::PENDING => 'Menunggu antrean',
            self::PROCESSING, self::SYNCING => 'Sedang disinkronkan',
            self::RETRYING => 'Dicoba ulang',
            self::SYNCED => 'Tersinkron',
            self::SYNC_FAILED, self::FAILED => 'Sinkronisasi gagal',
            self::CANCELLED => 'Dibatalkan',
            self::SKIPPED => 'Tidak berlaku (mandiri)',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::SYNCED => 'green',
            self::SYNCING, self::PROCESSING, self::PENDING, self::RETRYING => 'amber',
            self::SYNC_FAILED, self::FAILED => 'red',
            self::CANCELLED => 'gray',
            self::SKIPPED => 'blue',
            self::NOT_SYNCED => 'gray',
        };
    }

    /** Masih ada pekerjaan berjalan? */
    public function isInFlight(): bool
    {
        return in_array($this, [self::PENDING, self::PROCESSING, self::SYNCING, self::RETRYING], true);
    }

    /** Layak dicoba ulang otomatis? */
    public function isRetryable(): bool
    {
        return in_array($this, [self::FAILED, self::SYNC_FAILED, self::RETRYING, self::PENDING], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
