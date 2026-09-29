<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Visibilitas berkas LMS (§28) — terpisah dari `ContentVisibility` yang
 * mengatur visibilitas section/item terhadap peserta.
 *
 * Path penyimpanan pribadi tidak pernah dikirim ke klien; hanya berkas
 * PUBLIC yang boleh ditautkan langsung.
 */
enum FileVisibility: string
{
    case PUBLIC = 'PUBLIC';
    case COURSE = 'COURSE';
    case PRIVATE = 'PRIVATE';
    case SUBMISSION = 'SUBMISSION';
    case TEACHER_ONLY = 'TEACHER_ONLY';
    case ADMIN_ONLY = 'ADMIN_ONLY';

    public function label(): string
    {
        return match ($this) {
            self::PUBLIC => 'Umum',
            self::COURSE => 'Peserta course',
            self::PRIVATE => 'Pribadi',
            self::SUBMISSION => 'Lampiran tugas',
            self::TEACHER_ONLY => 'Guru pengampu',
            self::ADMIN_ONLY => 'Administrator',
        };
    }

    public function isPublic(): bool
    {
        return $this === self::PUBLIC;
    }

    public function storagePrefix(): string
    {
        return strtolower($this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
