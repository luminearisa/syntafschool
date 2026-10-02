<?php

namespace App\Modules\Hrd\Recruitment\Services;

use App\Modules\Hrd\Recruitment\Models\HrJobOpening;

/**
 * Pencari lowongan kecil: memisahkan "cari baris" dari "kelola corong",
 * sehingga layanan corong tidak perlu tahu cara lowongan dimuat (mis. cache).
 */
final class HrJobOpeningFinder
{
    public static function find(int $openingId): ?HrJobOpening
    {
        return HrJobOpening::query()->find($openingId);
    }
}
