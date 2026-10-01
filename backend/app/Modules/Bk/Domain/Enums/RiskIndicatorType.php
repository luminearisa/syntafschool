<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis penanda.
 *
 * Istilahnya sengaja bertingkat dari yang paling lunak: sebagian penanda hanya
 * menandai "ada yang perlu dilihat", bukan "ada risiko".
 */
enum RiskIndicatorType: string
{
    case RISK_INDICATOR = 'RISK_INDICATOR';
    case SUPPORT_INDICATOR = 'SUPPORT_INDICATOR';
    case ATTENTION_REQUIRED = 'ATTENTION_REQUIRED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::RISK_INDICATOR => 'Penanda risiko',
            self::SUPPORT_INDICATOR => 'Penanda kebutuhan dukungan',
            self::ATTENTION_REQUIRED => 'Perlu perhatian',
        };
    }

    public function defaultSeverity(): RiskSeverity
    {
        return match ($this) {
            self::ATTENTION_REQUIRED => RiskSeverity::LOW,
            self::SUPPORT_INDICATOR => RiskSeverity::MODERATE,
            self::RISK_INDICATOR => RiskSeverity::HIGH,
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
