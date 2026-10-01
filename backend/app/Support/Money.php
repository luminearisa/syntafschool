<?php

namespace App\Support;

use DivisionByZeroError;

/**
 * Aritmetika uang presisi tetap berbasis BCMath (string), standar akuntansi
 * yang dipakai modul Finance.
 *
 * **JANGAN gunakan float untuk uang.** Float tidak dapat mewakili 0,1 maupun
 * 0,2 dengan tepat, sehingga jumlah 0,1 + 0,2 tidak pernah benar-benar 0,3.
 * Selama ini modul mentoleransi selisih itu (`TOLERANCE = 0.005`); toleransi
 * seperti itu menyembunyikan ketidakseimbangan yang sesungguhnya, dan pada
 * jurnal bernilai besar selisihnya bisa melewati batas toleransi lalu ditolak
 * tanpa sebab yang dapat dijelaskan.
 *
 * Aturannya sekarang: float boleh masuk SATU KALI di batas sistem (dari input
 * atau dari kolom lama), lalu seluruh perhitungan memakai string. Uang skala 2
 * (decimal 18,2); kuantiti skala 4.
 */
final class Money
{
    public const SCALE = 2;

    public const SCALE_QUANTITY = 4;

    /** Normalisasi nilai ke string dengan skala tertentu. */
    public static function of(string|int|float|null $value, int $scale = self::SCALE): string
    {
        if ($value === null || $value === '') {
            $value = '0';
        }

        if (is_float($value)) {
            // Float hanya boleh masuk di sini. Format dengan presisi ekstra lebih
            // dulu agar nilai seperti 0.1 tidak menjadi '0.09999999'.
            $value = sprintf('%.'.($scale + 6).'F', $value);
        }

        return bcadd((string) $value, '0', $scale);
    }

    /** Kuantiti memakai skala 4 — bukan skala uang. */
    public static function quantity(string|int|float|null $value): string
    {
        return self::of($value, self::SCALE_QUANTITY);
    }

    public static function add(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        return bcadd(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        return bcsub(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    public static function mul(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        return bcmul(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    /** Pembagian; melempar bila pembagi nol (pembagi nol adalah cacat logika, bukan hasil nol). */
    public static function div(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        if (self::isZero($b, $scale)) {
            throw new DivisionByZeroError('Money::div pembagi tidak boleh nol.');
        }

        return bcdiv(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    /** Jumlah seluruh nilai; nilai kosong diabaikan. */
    public static function sum(iterable $values, int $scale = self::SCALE): string
    {
        $total = self::of(0, $scale);

        foreach ($values as $value) {
            $total = self::add($total, $value, $scale);
        }

        return $total;
    }

    public static function neg(string|int|float|null $a, int $scale = self::SCALE): string
    {
        return bcmul(self::of($a, $scale), '-1', $scale);
    }

    public static function abs(string|int|float|null $a, int $scale = self::SCALE): string
    {
        return self::isNegative($a, $scale) ? self::neg($a, $scale) : self::of($a, $scale);
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): int
    {
        return bccomp(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    public static function eq(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): bool
    {
        return self::cmp($a, $b, $scale) === 0;
    }

    public static function gt(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): bool
    {
        return self::cmp($a, $b, $scale) > 0;
    }

    public static function gte(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): bool
    {
        return self::cmp($a, $b, $scale) >= 0;
    }

    public static function lt(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): bool
    {
        return self::cmp($a, $b, $scale) < 0;
    }

    public static function lte(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): bool
    {
        return self::cmp($a, $b, $scale) <= 0;
    }

    public static function isZero(string|int|float|null $a, int $scale = self::SCALE): bool
    {
        return self::eq($a, 0, $scale);
    }

    public static function gtZero(string|int|float|null $a, int $scale = self::SCALE): bool
    {
        return self::gt($a, 0, $scale);
    }

    public static function isNegative(string|int|float|null $a, int $scale = self::SCALE): bool
    {
        return self::lt($a, 0, $scale);
    }

    /** Tampilan rupiah tanpa simbol; pemisah ribuan mengikuti locale Indonesia. */
    public static function format(string|int|float|null $value, int $scale = self::SCALE): string
    {
        return number_format((float) self::of($value, $scale), $scale, ',', '.');
    }
}
