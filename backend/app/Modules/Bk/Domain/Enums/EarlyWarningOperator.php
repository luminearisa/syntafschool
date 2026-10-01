<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Operator perbandingan aturan peringatan dini.
 *
 * Sengaja berupa enum, bukan teks bebas: aturan yang tersimpan dengan operator tak
 * dikenal akan diam-diam tidak pernah cocok, dan itu kegagalan yang senyap.
 */
enum EarlyWarningOperator: string
{
    case LT = 'LT';
    case LTE = 'LTE';
    case GT = 'GT';
    case GTE = 'GTE';
    case EQ = 'EQ';
    case NE = 'NE';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::LT => 'kurang dari',
            self::LTE => 'kurang dari atau sama dengan',
            self::GT => 'lebih dari',
            self::GTE => 'lebih dari atau sama dengan',
            self::EQ => 'sama dengan',
            self::NE => 'tidak sama dengan',
        };
    }

    /** Evaluasi ambang. Inilah satu-satunya tempat perbandingan ditentukan. */
    public function matches(float $observed, float $threshold): bool
    {
        return match ($this) {
            self::LT => $observed < $threshold,
            self::LTE => $observed <= $threshold,
            self::GT => $observed > $threshold,
            self::GTE => $observed >= $threshold,
            self::EQ => abs($observed - $threshold) < 0.00001,
            self::NE => abs($observed - $threshold) >= 0.00001,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::LT => '<', self::LTE => '<=', self::GT => '>', self::GTE => '>=', self::EQ => '=', self::NE => '!=',
        };
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
