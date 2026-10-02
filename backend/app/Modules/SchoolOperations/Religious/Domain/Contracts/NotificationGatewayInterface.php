<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Notifikasi (§64, §86).
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface NotificationGatewayInterface
{
    /** Kirim satu notifikasi. */
    public function send(array $payload): array;

    /** Kirim notifikasi ke daftar peran. */
    public function sendToRoles(array $roles, array $payload): array;

    /** Jadwalkan notifikasi pengingat. */
    public function schedule(array $payload): array;

    /** Ketersediaan modul notifikasi. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
