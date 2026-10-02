<?php

namespace App\Modules\Hrd\Core\Infrastructure;

use App\Modules\Hrd\Core\Domain\Contracts\FinancePostingProviderInterface;

/**
 * Implementasi bawaan untuk Finance & Accounting yang belum terpasang.
 *
 * Pola ini menjaga batas modul: HRD tetap berjalan penuh, peristiwanya tetap
 * tercatat, dan begitu modul tujuan tersedia cukup mengganti binding tanpa
 * mengubah satu pun layanan bisnis.
 */
class NullFinancePostingProvider implements FinancePostingProviderInterface
{
    /** @return array<string, mixed> */
    public function postJournal(array $journal, int $actorUserId): array
    {
        // Jurnal tetap tersimpan di hr_payroll_postings dan dikirim ulang oleh penjadwal.
        return ['reference' => null, 'status' => 'PENDING_FINANCE'];
    }

    /** @return array<string, mixed> */
    public function budgetAvailable(array $query): array
    {
        return ['available' => null, 'status' => 'UNKNOWN'];
    }

    public function costCenterFor(int $organizationId, ?int $departmentId = null): ?string
    {
        return null;
    }
}
