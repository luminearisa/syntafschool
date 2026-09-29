<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Status satu langkah tindak lanjut. */
enum ActionItemStatus: string
{
    case PENDING = 'PENDING';
    case IN_PROGRESS = 'IN_PROGRESS';
    case DONE = 'DONE';
    case VERIFIED = 'VERIFIED';
    case DROPPED = 'DROPPED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Belum Mulai',
            self::IN_PROGRESS => 'Berjalan',
            self::DONE => 'Selesai',
            self::VERIFIED => 'Diverifikasi',
            self::DROPPED => 'Dihentikan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
