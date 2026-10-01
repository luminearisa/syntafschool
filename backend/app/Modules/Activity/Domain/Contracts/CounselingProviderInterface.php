<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * BK — **hanya sinyal perkembangan**, bukan catatan konseling (§60).
 *
 * Kontrak ini sengaja sempit. Modul Activity tidak pernah menerima, menyimpan,
 * atau menampilkan catatan konseling; yang mengalir hanya ringkasan capaian
 * aktivitas yang memang sudah menjadi data bersama (mis. kepemimpinan,
 * partisipasi sosial).
 */
interface CounselingProviderInterface
{
    /** Apakah pengguna ini boleh melihat ringkasan aktivitas siswa tersebut. */
    public function canExposeSummary(int $userId, int $studentId): bool;

    /** @return list<array> sinyal perkembangan untuk BK (kosong bila tidak diizinkan) */
    public function developmentSignals(int $studentId): array;
}
