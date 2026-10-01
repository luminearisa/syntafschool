<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Tingkat perhatian/risiko pada penanda.
 *
 * Dipakai untuk mengurutkan pekerjaan, bukan untuk menilai anak.
 */
enum RiskSeverity: string
{
    case LOW = 'LOW';
    case MODERATE = 'MODERATE';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Rendah',
            self::MODERATE => 'Sedang',
            self::HIGH => 'Tinggi',
            self::CRITICAL => 'Mendesak',
        };
    }

    public function rank(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
