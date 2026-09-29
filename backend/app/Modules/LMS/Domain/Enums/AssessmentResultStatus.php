<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Status satu hasil penilaian (§12, §17).
 *
 * Alur: PENDING → GRADED → RETURNED → RELEASED → LOCKED. Siswa hanya melihat
 * hasil berstatus RETURNED/RELEASED/LOCKED sesuai kebijakan gradebook.
 */
enum AssessmentResultStatus: string
{
    case PENDING = 'PENDING';
    case GRADED = 'GRADED';
    case RETURNED = 'RETURNED';
    case RELEASED = 'RELEASED';
    case LOCKED = 'LOCKED';
    case EXEMPT = 'EXEMPT';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu penilaian',
            self::GRADED => 'Sudah dinilai',
            self::RETURNED => 'Dikembalikan',
            self::RELEASED => 'Dirilis',
            self::LOCKED => 'Terkunci',
            self::EXEMPT => 'Dibebaskan',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::PENDING => 'amber',
            self::GRADED => 'blue',
            self::RETURNED => 'brand',
            self::RELEASED => 'green',
            self::LOCKED => 'gray',
            self::EXEMPT => 'gray',
        };
    }

    /** Nilai boleh diubah tanpa izin khusus? */
    public function allowsGradeChange(): bool
    {
        return ! in_array($this, [self::LOCKED, self::EXEMPT], true);
    }

    /** Apakah status ini dianggap "sudah ada nilai"? */
    public function hasScore(): bool
    {
        return ! in_array($this, [self::PENDING, self::EXEMPT], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
