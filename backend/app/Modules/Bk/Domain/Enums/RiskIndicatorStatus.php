<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status penanganan penanda.
 *
 * `DISMISSED` berbeda dari `RESOLVED`: yang pertama berarti penanda ternyata tidak
 * relevan setelah ditinjau, yang kedua berarti sudah ditangani.
 */
enum RiskIndicatorStatus: string
{
    case OPEN = 'OPEN';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case REVIEWED = 'REVIEWED';
    case RESOLVED = 'RESOLVED';
    case DISMISSED = 'DISMISSED';
    case EXPIRED = 'EXPIRED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Terbuka',
            self::ACKNOWLEDGED => 'Diakui',
            self::UNDER_REVIEW => 'Sedang ditinjau',
            self::REVIEWED => 'Sudah ditinjau',
            self::RESOLVED => 'Selesai ditangani',
            self::DISMISSED => 'Tidak relevan',
            self::EXPIRED => 'Kedaluwarsa',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::OPEN, self::ACKNOWLEDGED, self::UNDER_REVIEW], true);
    }

    public function needsReview(): bool
    {
        return in_array($this, [self::OPEN, self::ACKNOWLEDGED], true);
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
