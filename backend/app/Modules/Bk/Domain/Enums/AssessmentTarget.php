<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Kepada siapa asesmen diberikan.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum AssessmentTarget: string
{
    case STUDENT = 'STUDENT';
    case PARENT = 'PARENT';
    case TEACHER = 'TEACHER';
    case COUNSELOR = 'COUNSELOR';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::STUDENT => 'Siswa',
            self::PARENT => 'Orang tua/wali',
            self::TEACHER => 'Guru',
            self::COUNSELOR => 'Konselor',
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
