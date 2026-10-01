<?php

namespace App\Modules\Workflow\Domain\Contracts;

use Carbon\CarbonInterface;

/**
 * Kalender bisnis untuk perhitungan SLA (§41, §70).
 *
 * SLA operasional sering kali berarti "48 jam kerja", bukan "48 jam kalender".
 * Kontrak ini memisahkan perhitungan itu dari engine sehingga sekolah dengan
 * kalender berbeda (Jumat–Sabtu libur, jam kerja asrama, zona waktu WITA)
 * cukup mengganti satu binding.
 */
interface BusinessCalendarInterface
{
    /** Tambahkan sejumlah menit kerja mulai dari `$from`. */
    public function addWorkingMinutes(CarbonInterface $from, int $minutes, ?string $calendarCode = null): CarbonInterface;

    /** Apakah waktu tersebut hari kerja menurut kalender. */
    public function isWorkingMoment(CarbonInterface $moment, ?string $calendarCode = null): bool;

    /** Jumlah menit kerja antara dua waktu (dipakai untuk metrik SLA). */
    public function minutesBetween(CarbonInterface $start, CarbonInterface $end, ?string $calendarCode = null): int;
}
