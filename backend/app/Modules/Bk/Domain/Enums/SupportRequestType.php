<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis permintaan dukungan dari portal.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum SupportRequestType: string
{
    case STUDENT_SELF = 'STUDENT_SELF';
    case PARENT = 'PARENT';
    case TEACHER = 'TEACHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::STUDENT_SELF => 'Permintaan siswa',
            self::PARENT => 'Permintaan orang tua',
            self::TEACHER => 'Permintaan guru',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
