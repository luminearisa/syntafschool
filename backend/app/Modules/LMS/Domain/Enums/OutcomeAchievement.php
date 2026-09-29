<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Capaian learning outcome (§28). Ambang batas dapat dikonfigurasi per
 * outcome maupun per sekolah sehingga tidak ada angka yang di-hard-code.
 */
enum OutcomeAchievement: string
{
    case NOT_ASSESSED = 'NOT_ASSESSED';
    case NOT_YET = 'NOT_YET';
    case DEVELOPING = 'DEVELOPING';
    case ACHIEVED = 'ACHIEVED';
    case MASTERED = 'MASTERED';

    public function label(): string
    {
        return match ($this) {
            self::NOT_ASSESSED => 'Belum dinilai',
            self::NOT_YET => 'Belum tercapai',
            self::DEVELOPING => 'Berkembang',
            self::ACHIEVED => 'Tercapai',
            self::MASTERED => 'Sangat mahir',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::NOT_ASSESSED => 'gray',
            self::NOT_YET => 'red',
            self::DEVELOPING => 'amber',
            self::ACHIEVED => 'green',
            self::MASTERED => 'brand',
        };
    }

    /**
     * Tentukan capaian dari skor 0–100 dan ambang batas.
     *
     * @param  array{developing?: float|int|null, achieved?: float|int|null, mastered?: float|int|null}  $thresholds
     */
    public static function fromScore(?float $score, array $thresholds = []): self
    {
        if ($score === null) {
            return self::NOT_ASSESSED;
        }

        $developing = (float) ($thresholds['developing'] ?? 60);
        $achieved = (float) ($thresholds['achieved'] ?? 75);
        $mastered = (float) ($thresholds['mastered'] ?? 90);

        return match (true) {
            $score >= $mastered => self::MASTERED,
            $score >= $achieved => self::ACHIEVED,
            $score >= $developing => self::DEVELOPING,
            default => self::NOT_YET,
        };
    }

    public function isAchieved(): bool
    {
        return in_array($this, [self::ACHIEVED, self::MASTERED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
