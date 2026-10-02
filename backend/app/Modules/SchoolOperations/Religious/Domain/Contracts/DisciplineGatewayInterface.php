<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Student Discipline (§26). Presensi keagamaan TIDAK otomatis menjadi sanksi — hanya bila institusi mengaktifkan.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface DisciplineGatewayInterface
{
    /** Teruskan fakta presensi (Present/Late/Absent/Excused) ke alur kedisiplinan. */
    public function forwardAttendanceFacts(array $payload): array;

    /** Apakah institusi mengaktifkan penerusan ke kedisiplinan. */
    public function isEnabled(): bool;

    /** Ketersediaan modul kedisiplinan. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
