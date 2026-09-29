<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Status sinkronisasi nilai ke SIAKAD (§41).
 */
enum GradeSyncStatus: string
{
    case NOT_SYNCED = 'NOT_SYNCED';
    case SYNCING = 'SYNCING';
    case SYNCED = 'SYNCED';
    case SYNC_FAILED = 'SYNC_FAILED';
    case SKIPPED = 'SKIPPED';

    public function label(): string
    {
        return match ($this) {
            self::NOT_SYNCED => 'Belum disinkronkan',
            self::SYNCING => 'Sedang disinkronkan',
            self::SYNCED => 'Tersinkron',
            self::SYNC_FAILED => 'Sinkronisasi gagal',
            self::SKIPPED => 'Tidak berlaku (mandiri)',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::NOT_SYNCED => 'gray',
            self::SYNCING => 'amber',
            self::SYNCED => 'green',
            self::SYNC_FAILED => 'red',
            self::SKIPPED => 'blue',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
