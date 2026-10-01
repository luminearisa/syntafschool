<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Kanal pengiriman notifikasi (§67).
 */
enum NotificationChannel: string
{
    case IN_APP = 'IN_APP';
    case EMAIL = 'EMAIL';
    case PUSH = 'PUSH';
    case WHATSAPP = 'WHATSAPP';

    public function label(): string
    {
        return match ($this) {
            self::IN_APP => 'Dalam Aplikasi',
            self::EMAIL => 'Email',
            self::PUSH => 'Push',
            self::WHATSAPP => 'WhatsApp',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
