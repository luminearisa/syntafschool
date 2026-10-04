<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Support;

/**
 * Aturan pembulatan deterministik modul laundry.
 *
 * Berat/potongan sampai 3 desimal (kg bisa 0.125), uang sampai 2 desimal.
 * Semua perhitungan harga memakai helper ini agar hasil selalu sama untuk
 * input yang sama (Rule 7: paid portion deterministic).
 */
final class LaundryMath
{
    public static function quantity(float|int|string|null $value): float
    {
        return round((float) $value, 3);
    }

    public static function money(float|int|string|null $value): float
    {
        return round((float) $value, 2);
    }

    /** Sisa jatah tidak pernah negatif (Rule 6). */
    public static function clampNonNegative(float $value): float
    {
        return max(0.0, $value);
    }
}
