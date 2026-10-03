<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Infrastructure\Providers;

use App\Modules\SchoolOperations\Competitions\Domain\Contracts\FinanceProviderInterface;

/** Safe default. Competition stores only references/status; it never charges locally. */
class UnavailableFinanceProvider implements FinanceProviderInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return 'Finance provider belum dikonfigurasi.';
    }

    public function requestRegistrationCharge(array $payload): array
    {
        return ['status' => 'UNAVAILABLE', 'reference' => null];
    }

    public function paymentStatus(string $reference): ?array
    {
        return null;
    }
}
