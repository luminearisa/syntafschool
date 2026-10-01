<?php

namespace App\Modules\Asset\Domain\Enums;

enum ReplacementRecommendation: string
{
    case MONITOR = 'MONITOR';
    case MAINTAIN = 'MAINTAIN';
    case REPAIR = 'REPAIR';
    case REPLACE_REVIEW = 'REPLACE_REVIEW';

    public function rank(): int
    {
        return match ($this) {
            self::MONITOR => 1,
            self::MAINTAIN => 2,
            self::REPAIR => 3,
            self::REPLACE_REVIEW => 4,
        };
    }

    /** Rekomendasi terberat dari beberapa usulan. */
    public static function worst(self ...$items): self
    {
        $worst = self::MONITOR;

        foreach ($items as $item) {
            if ($item->rank() > $worst->rank()) {
                $worst = $item;
            }
        }

        return $worst;
    }
}
