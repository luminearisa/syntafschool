<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Contracts;

/** Finance owns charges, receipts, refunds and accounting references. */
interface FinanceProviderInterface extends ProviderAvailabilityInterface
{
    /** @return array{status:string, reference?:string|null} */
    public function requestRegistrationCharge(array $payload): array;

    /** @return array{status:string, reference?:string|null}|null */
    public function paymentStatus(string $reference): ?array;
}
