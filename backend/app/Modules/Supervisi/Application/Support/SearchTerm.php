<?php

namespace App\Modules\Supervisi\Application\Support;

/**
 * Pembersih kata kunci pencarian untuk klausa `LIKE`.
 *
 * `%` dan `_` adalah wildcard SQL: pencarian "%" akan mengembalikan **seluruh**
 * baris dan `_` mencocokkan karakter apa pun — selain hasil yang membingungkan,
 * ini membuat pemindaian tabel penuh dari satu kotak pencarian. Karena tiap
 * basis data memperlakukan karakter escape secara berbeda (SQLite tidak
 * mengenal `\` tanpa klausa `ESCAPE`), wildcard di sini **dibuang** — kata kunci
 * "50%" tetap menemukan "target 50% terlampaui".
 */
final class SearchTerm
{
    public const MAX_LENGTH = 60;

    /** Bersihkan kata kunci; kembalikan null bila tidak ada isinya. */
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $term = str_replace(['%', '_', '\\'], ' ', $value);
        $term = trim(preg_replace('/\s+/u', ' ', $term) ?? '');

        if ($term === '') {
            return null;
        }

        return mb_substr($term, 0, self::MAX_LENGTH);
    }

    /** Pola `LIKE` yang aman, atau null bila kata kuncinya kosong. */
    public static function like(?string $value): ?string
    {
        $term = self::clean($value);

        return $term === null ? null : '%'.$term.'%';
    }
}
