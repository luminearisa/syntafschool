<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Status pengiriman notifikasi. Baris `SKIPPED` adalah bukti idempotensi:
 * pesan yang sudah pernah terkirim tidak dikirim dua kali (§68).
 */
enum NotificationStatus: string
{
    case PENDING = 'PENDING';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case SKIPPED = 'SKIPPED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
