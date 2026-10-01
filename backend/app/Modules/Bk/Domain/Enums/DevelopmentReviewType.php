<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis peninjauan pengembangan.
 *
 * Urutannya Baseline → Review 1..n → Final. Baseline wajib ada lebih dulu, sehingga
 * "kemajuan" selalu punya titik awal pembanding.
 */
enum DevelopmentReviewType: string
{
    case BASELINE = 'BASELINE';
    case REVIEW = 'REVIEW';
    case FINAL = 'FINAL';
    case ADHOC = 'ADHOC';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::BASELINE => 'Baseline',
            self::REVIEW => 'Peninjauan berkala',
            self::FINAL => 'Peninjauan akhir',
            self::ADHOC => 'Peninjauan khusus',
        };
    }

    /** Nomor urut bawaan untuk jenis ini. */
    public function defaultSequence(): int
    {
        return match ($this) {
            self::BASELINE => 1,
            default => 2,
        };
    }

    public function isBaseline(): bool
    {
        return $this === self::BASELINE;
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
