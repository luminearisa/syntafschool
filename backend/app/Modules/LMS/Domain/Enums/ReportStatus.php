<?php

namespace App\Modules\LMS\Domain\Enums;

/** Status ekspor laporan (§22: laporan berat dijalankan di antrean). */
enum ReportStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case READY = 'READY';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu antrean',
            self::PROCESSING => 'Sedang dibuat',
            self::READY => 'Siap diunduh',
            self::FAILED => 'Gagal',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::READY => 'green',
            self::PENDING, self::PROCESSING => 'amber',
            self::FAILED => 'red',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
