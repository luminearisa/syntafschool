<?php

namespace App\Modules\LMS\Domain\Enums;

/** Visibilitas section & learning item terhadap peserta. */
enum ContentVisibility: string
{
    case VISIBLE = 'VISIBLE';
    case HIDDEN = 'HIDDEN';

    public function label(): string
    {
        return match ($this) {
            self::VISIBLE => 'Tampil',
            self::HIDDEN => 'Disembunyikan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
