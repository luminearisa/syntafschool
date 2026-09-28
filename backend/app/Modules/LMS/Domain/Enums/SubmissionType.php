<?php

namespace App\Modules\LMS\Domain\Enums;

/** Bentuk pengumpulan tugas (§4). */
enum SubmissionType: string
{
    case NONE = 'NONE';
    case ONLINE_TEXT = 'ONLINE_TEXT';
    case FILE = 'FILE';
    case ONLINE_TEXT_AND_FILE = 'ONLINE_TEXT_AND_FILE';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Tanpa pengumpulan',
            self::ONLINE_TEXT => 'Teks daring',
            self::FILE => 'Unggah berkas',
            self::ONLINE_TEXT_AND_FILE => 'Teks daring + berkas',
        };
    }

    public function allowsText(): bool
    {
        return in_array($this, [self::ONLINE_TEXT, self::ONLINE_TEXT_AND_FILE], true);
    }

    public function allowsFile(): bool
    {
        return in_array($this, [self::FILE, self::ONLINE_TEXT_AND_FILE], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
