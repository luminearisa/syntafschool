<?php

namespace App\Modules\LMS\Domain\Enums;

enum ResourceType: string
{
    case PAGE = 'PAGE';
    case FILE = 'FILE';
    case VIDEO = 'VIDEO';
    case AUDIO = 'AUDIO';
    case URL = 'URL';
    case BOOK = 'BOOK';
    case FOLDER = 'FOLDER';
    case TEXT = 'TEXT';

    public function label(): string
    {
        return match ($this) {
            self::PAGE => 'Halaman',
            self::FILE => 'Berkas',
            self::VIDEO => 'Video',
            self::AUDIO => 'Audio',
            self::URL => 'Tautan',
            self::BOOK => 'Buku',
            self::FOLDER => 'Folder',
            self::TEXT => 'Teks',
        };
    }

    /** Ikon Lucide yang dipakai frontend (satu sumber kebenaran). */
    public function icon(): string
    {
        return match ($this) {
            self::PAGE => 'file-text',
            self::FILE => 'paperclip',
            self::VIDEO => 'play-circle',
            self::AUDIO => 'headphones',
            self::URL => 'link',
            self::BOOK => 'book-open',
            self::FOLDER => 'folder',
            self::TEXT => 'align-left',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
