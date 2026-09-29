<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Sumber penilaian (untuk tinjauan multi-sumber / 360°). */
enum AppraisalSource: string
{
    case SELF = 'SELF';
    case SUPERVISOR = 'SUPERVISOR';
    case PEER = 'PEER';
    case SUBORDINATE = 'SUBORDINATE';
    case STUDENT = 'STUDENT';
    case PARENT = 'PARENT';

    public function label(): string
    {
        return match ($this) {
            self::SELF => 'Penilaian Diri',
            self::SUPERVISOR => 'Atasan / Supervisor',
            self::PEER => 'Rekan Sejawat',
            self::SUBORDINATE => 'Bawahan',
            self::STUDENT => 'Peserta Didik',
            self::PARENT => 'Orang Tua / Wali',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
