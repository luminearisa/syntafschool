<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Tindakan yang diambil mesin aturan ketika ambang terlampaui.
 *
 * Semua tindakan bersifat **menambah pekerjaan untuk ditinjau manusia**, tidak ada
 * yang mengubah status siswa secara otomatis.
 */
enum EarlyWarningAction: string
{
    case CREATE_SUPPORT_INDICATOR = 'CREATE_SUPPORT_INDICATOR';
    case CREATE_BK_REVIEW = 'CREATE_BK_REVIEW';
    case CREATE_ACADEMIC_SUPPORT_INDICATOR = 'CREATE_ACADEMIC_SUPPORT_INDICATOR';
    case CREATE_REFERRAL = 'CREATE_REFERRAL';
    case NOTIFY_COORDINATOR = 'NOTIFY_COORDINATOR';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::CREATE_SUPPORT_INDICATOR => 'Buat penanda dukungan',
            self::CREATE_BK_REVIEW => 'Buat tinjauan BK',
            self::CREATE_ACADEMIC_SUPPORT_INDICATOR => 'Buat penanda dukungan akademik',
            self::CREATE_REFERRAL => 'Buat rujukan otomatis',
            self::NOTIFY_COORDINATOR => 'Beri tahu koordinator',
        };
    }

    /** Jenis penanda yang dilahirkan tindakan ini. */
    public function indicatorType(): RiskIndicatorType
    {
        return match ($this) {
            self::CREATE_ACADEMIC_SUPPORT_INDICATOR => RiskIndicatorType::SUPPORT_INDICATOR,
            self::CREATE_REFERRAL => RiskIndicatorType::RISK_INDICATOR,
            self::CREATE_BK_REVIEW, self::CREATE_SUPPORT_INDICATOR => RiskIndicatorType::SUPPORT_INDICATOR,
            self::NOTIFY_COORDINATOR => RiskIndicatorType::ATTENTION_REQUIRED,
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
