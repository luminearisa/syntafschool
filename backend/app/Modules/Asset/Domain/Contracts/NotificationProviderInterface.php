<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Notifikasi — pemberitahuan ke pengguna atau peran.
 *
 * Penerima dapat berupa daftar pengguna maupun nama peran, supaya pengingat
 * tidak bergantung pada satu orang tertentu yang mungkin sudah pindah tugas.
 */
interface NotificationProviderInterface
{
    /** @param list<int> $userIds @param list<string> $roles */
    public function notify(array $userIds, array $roles, string $event, array $payload): void;

    /** Pemberitahuan massal untuk satu peristiwa (mis. fasilitas ditutup). */
    public function notifyMany(array $recipients, string $event, array $payload): int;
}
