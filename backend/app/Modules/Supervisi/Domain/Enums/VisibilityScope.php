<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Siapa yang boleh melihat sebuah berkas/bukti. */
enum VisibilityScope: string
{
    case SUPERVISOR = 'SUPERVISOR';     // hanya penilai
    case SUPERVISEE = 'SUPERVISEE';     // penilai + yang dinilai
    case INTERNAL = 'INTERNAL';         // semua pemegang izin modul
    case PUBLIC = 'PUBLIC';             // dapat dibagikan (mis. lampiran laporan)

    public function label(): string
    {
        return match ($this) {
            self::SUPERVISOR => 'Hanya Supervisor',
            self::SUPERVISEE => 'Supervisor & Yang Disupervisi',
            self::INTERNAL => 'Internal Sekolah',
            self::PUBLIC => 'Dapat Dibagikan',
        };
    }

    public function allowsSupervisee(): bool
    {
        return $this !== self::SUPERVISOR;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
