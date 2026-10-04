<?php

namespace App\Modules\StudentProgress\Support;

/**
 * Helper ambang poin pelanggaran siswa.
 *
 * Ambang default 100 poin. Level peringatan:
 * - `warning`  → total >= ambang (100)
 * - `critical` → total >= 2x ambang (200)
 * - `info`     → total di bawah ambang
 *
 * Faktor kritis dibuat konstanta agar ambang kustom tetap berskala (mis. ambang
 * 50 menghasilkan level kritis pada 100).
 */
final class StudentViolationPoint
{
    public const DEFAULT_THRESHOLD = 100;

    public const CRITICAL_FACTOR = 2;

    public static function criticalThreshold(int $threshold = self::DEFAULT_THRESHOLD): int
    {
        return $threshold * self::CRITICAL_FACTOR;
    }

    /**
     * Level peringatan untuk `total` poin pada `threshold` tertentu.
     */
    public static function levelFor(int $total, int $threshold = self::DEFAULT_THRESHOLD): string
    {
        if ($total >= self::criticalThreshold($threshold)) {
            return 'critical';
        }

        if ($total >= $threshold) {
            return 'warning';
        }

        return 'info';
    }

    public static function exceeded(int $total, int $threshold = self::DEFAULT_THRESHOLD): bool
    {
        return $total >= $threshold;
    }
}
