<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Strategi penyelesaian konflik impor (§14).
 *
 * Bawaan `SKIP`: impor tidak pernah menimpa course yang sudah ada tanpa
 * pilihan eksplisit pengguna.
 */
enum ImportConflictStrategy: string
{
    case SKIP = 'SKIP';
    case REPLACE = 'REPLACE';
    case DUPLICATE = 'DUPLICATE';
    case MERGE = 'MERGE';

    public function label(): string
    {
        return match ($this) {
            self::SKIP => 'Lewati yang sudah ada',
            self::REPLACE => 'Ganti isi yang bertabrakan',
            self::DUPLICATE => 'Buat salinan baru',
            self::MERGE => 'Gabungkan ke course yang ada',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SKIP => 'Item dengan judul sama dilewati; sisanya ditambahkan.',
            self::REPLACE => 'Item dengan judul sama diperbarui dari berkas impor.',
            self::DUPLICATE => 'Setiap item dibuat baru dengan judul bersufiks.',
            self::MERGE => 'Isi course tujuan dipertahankan, item baru ditambahkan.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
