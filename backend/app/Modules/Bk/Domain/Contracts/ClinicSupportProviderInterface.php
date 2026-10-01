<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Ringkasan kesehatan **tanpa diagnosis** untuk profil dukungan BK.

Sengaja hanya berupa penanda (berapa kunjungan, apakah ada tindak lanjut) supaya
BK mengetahui ada kebutuhan kesehatan tanpa membuka rekam medis.
 */
interface ClinicSupportProviderInterface
{

    /**
     * @return array{visits_90d: int, follow_up_pending: int, urgent_flag: bool, last_visit: ?string}|null
     */
    public function summary(int $studentId): ?array;
}
