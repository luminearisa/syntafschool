<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Jenis berkas bukti yang dilampirkan pada kegiatan supervisi. */
enum EvidenceKind: string
{
    case PHOTO = 'PHOTO';
    case VIDEO = 'VIDEO';
    case AUDIO = 'AUDIO';
    case DOCUMENT = 'DOCUMENT';
    case LINK = 'LINK';

    public function label(): string
    {
        return match ($this) {
            self::PHOTO => 'Foto',
            self::VIDEO => 'Video',
            self::AUDIO => 'Rekaman Suara',
            self::DOCUMENT => 'Dokumen',
            self::LINK => 'Tautan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
