<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Mesin persetujuan — dipakai untuk transfer, penghapusan, dan permintaan
 * bernilai besar. Bila mesin tidak tersedia, binding bawaan menyetujui
 * otomatis HANYA bila kebijakan sekolah memang tidak menuntut persetujuan;
 * selain itu permintaan tetap menunggu (tidak diam-diam disetujui).
 */
interface WorkflowProviderInterface
{
    /** Mulai proses persetujuan; mengembalikan id proses. */
    public function start(array $payload): array;

    /** Lanjutkan proses yang menunggu keputusan. */
    public function decide(array $payload): array;

    /** Keadaan proses untuk sebuah subjek. */
    public function status(string $subjectType, int $subjectId): ?array;

    public function isApproved(string $subjectType, int $subjectId): bool;
}
