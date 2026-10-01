<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Klasifikasi operasional tingkat keparahan. Bukan diagnosis klinis.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum Severity: string
{
    case LEVEL_1 = 'LEVEL_1';
    case LEVEL_2 = 'LEVEL_2';
    case LEVEL_3 = 'LEVEL_3';
    case LEVEL_4 = 'LEVEL_4';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::LEVEL_1 => 'Level 1 \u2014 ringan',
            self::LEVEL_2 => 'Level 2 \u2014 sedang',
            self::LEVEL_3 => 'Level 3 \u2014 berat',
            self::LEVEL_4 => 'Level 4 \u2014 kritis',
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

    /** Angka tingkat keparahan 1\u20134 (klasifikasi operasional, bukan diagnosis). */
    public function level(): int
    {
        return match ($this) {
            self::LEVEL_1 => 1,
            self::LEVEL_2 => 2,
            self::LEVEL_3 => 3,
            self::LEVEL_4 => 4,
        };
    }

    /** Perlu eskalasi ke koordinator/manajemen sejak awal. */
    public function requiresEscalation(): bool
    {
        return $this->level() >= 3;
    }

    /** Dari prioritas: tingkat keparahan yang disarankan. */
    public static function fromPriority(Priority $priority): self
    {
        return match ($priority) {
            Priority::URGENT => self::LEVEL_4,
            Priority::HIGH => self::LEVEL_3,
            Priority::NORMAL => self::LEVEL_2,
            Priority::LOW => self::LEVEL_1,
        };
    }
}
