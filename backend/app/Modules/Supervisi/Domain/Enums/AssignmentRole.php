<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Peran seseorang di dalam sebuah penugasan supervisi. */
enum AssignmentRole: string
{
    case UTAMA = 'UTAMA';           // penilai utama
    case PENDAMPING = 'PENDAMPING'; // menemani/observer kedua
    case MODERATOR = 'MODERATOR';   // menengahi keberatan & kalibrasi
    case MENTOR = 'MENTOR';         // pendamping pengembangan

    public function label(): string
    {
        return match ($this) {
            self::UTAMA => 'Penilai Utama',
            self::PENDAMPING => 'Pendamping',
            self::MODERATOR => 'Moderator',
            self::MENTOR => 'Mentor',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
