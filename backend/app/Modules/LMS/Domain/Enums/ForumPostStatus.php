<?php

namespace App\Modules\LMS\Domain\Enums;

/** Status moderasi kiriman forum (§35). */
enum ForumPostStatus: string
{
    case VISIBLE = 'VISIBLE';
    case PENDING = 'PENDING';
    case HIDDEN = 'HIDDEN';
    case DELETED = 'DELETED';

    public function label(): string
    {
        return match ($this) {
            self::VISIBLE => 'Tampil',
            self::PENDING => 'Menunggu moderasi',
            self::HIDDEN => 'Disembunyikan',
            self::DELETED => 'Dihapus',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::VISIBLE => 'green',
            self::PENDING => 'amber',
            self::HIDDEN, self::DELETED => 'red',
        };
    }

    /** Terlihat oleh siswa lain. */
    public function isPublic(): bool
    {
        return $this === self::VISIBLE;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
