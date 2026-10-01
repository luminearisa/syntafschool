<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum MaterialType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum MaterialType: string
{
    case DOCUMENT = 'DOCUMENT';
    case LINK = 'LINK';
    case VIDEO = 'VIDEO';
    case PRESENTATION = 'PRESENTATION';
    case FILE = 'FILE';
    case LMS_CONTENT = 'LMS_CONTENT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DOCUMENT => 'Dokumen',
            self::LINK => 'Tautan',
            self::VIDEO => 'Video',
            self::PRESENTATION => 'Presentasi',
            self::FILE => 'Berkas',
            self::LMS_CONTENT => 'Materi LMS',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
