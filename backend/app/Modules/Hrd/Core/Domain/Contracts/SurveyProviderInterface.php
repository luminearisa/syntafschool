<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Titik integrasi ke luar modul HRD: Survey & Feedback ERP.
 *
 * Bila modul tujuan belum terpasang, implementasi bawaan mengembalikan jawaban
 * "belum tersedia" dan peristiwanya tetap tercatat di outbox — bukan membuat
 * modul HRD gagal. Pola ini sama dengan Phase 1 dan menjaga batas modul.
 */
interface SurveyProviderInterface
{
    /**
     * Menerbitkan survei engagement/pulse.
     *
     * @return array<string, mixed>
     */
    public function publish(array $survey, int $actorUserId): array;
    /**
     * Mengambil tanggapan agregat (bukan identitas).
     *
     * @return array<string, mixed>
     */
    public function responses(string $surveyReference, array $filters = []): array;
    /**
     * Ringkasan skor untuk analitik.
     *
     * @return array<string, mixed>
     */
    public function aggregate(string $surveyReference): array;
}
