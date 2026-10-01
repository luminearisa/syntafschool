<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Kegiatan siswa — sumber permintaan peminjaman alat dan booking ruang oleh
 * organisasi siswa. Modul Asset menyimpan aset sebagai milik sekolah; kegiatan
 * hanya meminjam.
 */
interface StudentActivityProviderInterface
{
    public function activity(int $activityId): ?array;

    /** Pinjaman/booking yang masih berjalan untuk sebuah kegiatan. */
    public function openCommitments(int $activityId): array;

    public function attachAsset(int $activityId, array $assetPayload): array;
}
