<?php

namespace App\Modules\Hrd\Core\Infrastructure;

use App\Modules\Hrd\Core\Domain\Contracts\HelpdeskProviderInterface;

/**
 * Implementasi bawaan untuk Helpdesk ERP yang belum terpasang.
 *
 * Pola ini menjaga batas modul: HRD tetap berjalan penuh, peristiwanya tetap
 * tercatat, dan begitu modul tujuan tersedia cukup mengganti binding tanpa
 * mengubah satu pun layanan bisnis.
 */
class NullHelpdeskProvider implements HelpdeskProviderInterface
{
    /** @return array<string, mixed> */
    public function open(array $ticket, int $actorUserId): array
    {
        return ['reference' => null, 'status' => 'LOCAL_ONLY', 'note' => 'Helpdesk ERP belum terpasang; tiket dikelola di HRD.'];
    }

    /** @return array<string, mixed> */
    public function comment(string $ticketReference, string $body, int $actorUserId): array
    {
        return ['reference' => $ticketReference, 'status' => 'LOCAL_ONLY'];
    }

    /** @return array<string, mixed> */
    public function close(string $ticketReference, int $actorUserId, ?string $resolution = null): array
    {
        return ['reference' => $ticketReference, 'status' => 'LOCAL_ONLY'];
    }
}
