<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Status rencana tindak lanjut (RTL). */
enum ActionPlanStatus: string
{
    case DRAFT = 'DRAFT';
    case ONGOING = 'ONGOING';
    case DONE = 'DONE';
    case VERIFIED = 'VERIFIED';
    case OVERDUE = 'OVERDUE';
    case DROPPED = 'DROPPED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::ONGOING => 'Berjalan',
            self::DONE => 'Selesai Dilaporkan',
            self::VERIFIED => 'Terverifikasi',
            self::OVERDUE => 'Lewat Tenggat',
            self::DROPPED => 'Dihentikan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
