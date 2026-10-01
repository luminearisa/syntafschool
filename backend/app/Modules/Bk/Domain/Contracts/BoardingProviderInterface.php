<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Ringkasan asrama & komitmen waktu santri untuk deteksi bentrok jadwal konseling.
 */
interface BoardingProviderInterface
{

    /**
     * @return array{dormitory: ?string, room: ?string, status: ?string, is_boarder: bool}|null
     */
    public function summary(int $studentId): ?array;

    /**
     * Komitmen waktu santri pada satu tanggal (kegiatan asrama rutin) untuk
     * deteksi bentrok jadwal konseling.
     *
     * @return list<array{label: string, start: string, end: string}>
     */
    public function commitments(int $studentId, string $date): array;
}
