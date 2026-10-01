<?php

namespace App\Support\Accounting;

use App\Models\FinanceAccount;

/**
 * Akar kelompok akun (1..5) — dipakai kebijakan dana, laporan, dan aturan
 * dimensi.
 *
 * Standar rujukan memisahkan akun menjadi kelompok 1 Aset, 2 Kewajiban,
 * 3 Ekuitas, 4 Pendapatan, 5 Beban, dan menurunkan akar kelompok dari KODE
 * akun — bukan dari label tipe yang bisa diubah orang lewat layar. Di sini akar
 * diturunkan dengan urutan: digit pertama kode bila 1..5; kalau tidak, dipetakan
 * dari `type` kolom; kalau itu pun kosong, `null` (dan pemanggil yang memutuskan
 * apa artinya "tidak diketahui").
 *
 * Konsistensi kode vs tipe sengaja TIDAK dipaksakan di sini: data lama banyak
 * yang berkode bebas, dan menolaknya akan menghentikan laporan yang selama ini
 * benar. Yang dilakukan adalah menyediakan `isConsistent()` agar selisihnya
 * dapat dilaporkan sebagai temuan, bukan kegagalan senyap.
 */
final class CoaHierarchy
{
    public const ASET = '1';
    public const KEWAJIBAN = '2';
    public const EKUITAS = '3';
    public const PENDAPATAN = '4';
    public const BEBAN = '5';

    public const LABELS = [
        self::ASET => 'Aset',
        self::KEWAJIBAN => 'Kewajiban',
        self::EKUITAS => 'Ekuitas',
        self::PENDAPATAN => 'Pendapatan',
        self::BEBAN => 'Beban',
    ];

    /** Akar kelompok dari sebuah kode akun. */
    public static function dariKode(?string $kode): ?string
    {
        if ($kode === null || $kode === '') {
            return null;
        }

        $digit = substr(trim($kode), 0, 1);

        return in_array($digit, [self::ASET, self::KEWAJIBAN, self::EKUITAS, self::PENDAPATAN, self::BEBAN], true)
            ? $digit
            : null;
    }

    /** Akar kelompok dari sebuah akun (kode lebih dulu, lalu tipe). */
    public static function dariAkun(FinanceAccount|string|null $account): ?string
    {
        if ($account === null) {
            return null;
        }

        if ($account instanceof FinanceAccount) {
            return self::dariKode($account->code) ?? self::dariTipe($account->type);
        }

        return self::dariKode($account);
    }

    public static function dariTipe(?string $type): ?string
    {
        return match ($type) {
            'asset' => self::ASET,
            'liability' => self::KEWAJIBAN,
            'equity' => self::EKUITAS,
            'revenue' => self::PENDAPATAN,
            'expense' => self::BEBAN,
            default => null,
        };
    }

    public static function isBeban(?string $kode): bool
    {
        return self::dariKode($kode) === self::BEBAN;
    }

    public static function isPendapatan(?string $kode): bool
    {
        return self::dariKode($kode) === self::PENDAPATAN;
    }

    /** Akun neraca: aset, kewajiban, ekuitas. */
    public static function isNeraca(?string $kode): bool
    {
        return in_array(self::dariKode($kode), [self::ASET, self::KEWAJIBAN, self::EKUITAS], true);
    }

    /** Akun laba rugi: pendapatan & beban. */
    public static function isLabaRugi(?string $kode): bool
    {
        return in_array(self::dariKode($kode), [self::PENDAPATAN, self::BEBAN], true);
    }

    /**
     * Baris yang dimensinya dipusatkan ke unit penampung: liabilitas apa pun dan
     * seluruh rekening kas/bank. Unit adalah pusat laba; neraca hanya bermakna di
     * tingkat yayasan, sehingga angka "kas per unit" tidak boleh lahir dari
     * pembagian yang sebenarnya hanya menumpang satu rekening.
     */
    public static function barisDipusatkan(?string $kode, bool $isKasBank): bool
    {
        return self::dariKode($kode) === self::KEWAJIBAN || $isKasBank;
    }

    public static function label(?string $akar): string
    {
        return self::LABELS[$akar] ?? 'Tidak diketahui';
    }

    /** Kode akun sejalan dengan tipe akunnya? */
    public static function isConsistent(?string $kode, ?string $type): bool
    {
        $dariKode = self::dariKode($kode);
        $dariTipe = self::dariTipe($type);

        return $dariKode === null || $dariTipe === null || $dariKode === $dariTipe;
    }
}
