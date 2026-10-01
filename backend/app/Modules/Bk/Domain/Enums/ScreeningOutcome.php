<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Rekomendasi hasil screening \u2014 bukan diagnosis.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum ScreeningOutcome: string
{
    case NO_ACTION = 'NO_ACTION';
    case MONITOR = 'MONITOR';
    case COUNSELING = 'COUNSELING';
    case INTERVENTION = 'INTERVENTION';
    case PARENT_MEETING = 'PARENT_MEETING';
    case TEAM_REVIEW = 'TEAM_REVIEW';
    case REFERRAL = 'REFERRAL';
    case URGENT_ESCALATION = 'URGENT_ESCALATION';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::NO_ACTION => 'Tidak perlu tindakan',
            self::MONITOR => 'Pantau',
            self::COUNSELING => 'Konseling',
            self::INTERVENTION => 'Intervensi',
            self::PARENT_MEETING => 'Pertemuan orang tua',
            self::TEAM_REVIEW => 'Rapat tim',
            self::REFERRAL => 'Rujuk ke pihak lain',
            self::URGENT_ESCALATION => 'Eskalasi mendesak',
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

    /** Butuh langkah nyata setelah screening. */
    public function requiresAction(): bool
    {
        return ! in_array($this, [self::NO_ACTION, self::MONITOR], true);
    }

    /** Harus ditangani pada hari yang sama. */
    public function isUrgent(): bool
    {
        return in_array($this, [self::URGENT_ESCALATION, self::REFERRAL], true);
    }

    /** Rekomendasi: kasus dibuka dengan tipe yang sesuai. */
    public function suggestedCaseType(): ?CaseType
    {
        return match ($this) {
            self::COUNSELING => CaseType::INDIVIDUAL_COUNSELING,
            self::INTERVENTION => CaseType::DEVELOPMENT,
            self::PARENT_MEETING => CaseType::FAMILY_SUPPORT,
            self::TEAM_REVIEW => CaseType::DEVELOPMENT,
            self::REFERRAL => CaseType::OTHER,
            self::URGENT_ESCALATION => CaseType::CRISIS_SUPPORT,
            default => null,
        };
    }
}
