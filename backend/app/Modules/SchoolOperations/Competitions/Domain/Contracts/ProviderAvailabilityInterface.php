<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Contracts;

/** Capability probe shared by optional ERP integrations. */
interface ProviderAvailabilityInterface
{
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;
}
