<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Prioritas penanganan \u2014 makin kecil bobotnya makin didahulukan.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum Priority: string
{
    case LOW = 'LOW';
    case NORMAL = 'NORMAL';
    case HIGH = 'HIGH';
    case URGENT = 'URGENT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Rendah',
            self::NORMAL => 'Normal',
            self::HIGH => 'Tinggi',
            self::URGENT => 'Mendesak',
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

    /** Urutan penanganan: makin kecil makin didahulukan. */
    public function weight(): int
    {
        return match ($this) {
            self::URGENT => 1,
            self::HIGH => 2,
            self::NORMAL => 3,
            self::LOW => 4,
        };
    }

    /** Butuh perhatian pada hari yang sama. */
    public function isCritical(): bool
    {
        return in_array($this, [self::URGENT, self::HIGH], true);
    }

    /** Tenggat tinjauan bawaan (hari) bila kasus dibuka dengan prioritas ini. */
    public function defaultReviewDays(): int
    {
        return match ($this) {
            self::URGENT => 3,
            self::HIGH => 7,
            self::NORMAL => 21,
            self::LOW => 30,
        };
    }
}
