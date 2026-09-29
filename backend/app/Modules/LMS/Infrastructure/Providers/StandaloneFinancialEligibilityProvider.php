<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Modules\LMS\Domain\Contracts\FinancialEligibilityProviderInterface;
use App\Modules\LMS\Domain\Enums\FinancialEligibilityStatus;

/**
 * Standalone Mode: tidak ada modul keuangan → selalu layak.
 *
 * Sengaja mengembalikan ELIGIBLE (bukan UNKNOWN) supaya kebijakan pembatasan
 * tidak pernah memblokir LMS mandiri (§47).
 */
class StandaloneFinancialEligibilityProvider implements FinancialEligibilityProviderInterface
{
    public function key(): string
    {
        return 'standalone';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function eligibilityFor(int $studentId): array
    {
        return [
            'status' => FinancialEligibilityStatus::ELIGIBLE->value,
            'outstanding_amount' => 0.0,
            'overdue_count' => 0,
            'reason' => null,
            'checked_at' => now()->toIso8601String(),
            'source' => 'standalone',
        ];
    }

    public function restrictedStudentIds(int $limit = 100): array
    {
        return [];
    }
}
