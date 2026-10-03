<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Infrastructure\Providers;

use App\Modules\SchoolOperations\Competitions\Domain\Contracts\CertificateProviderInterface;

/** No local certificate engine is created: delegate once an ERP provider exists. */
class UnavailableCertificateProvider implements CertificateProviderInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return 'Certificate provider belum dikonfigurasi.';
    }

    public function generate(array $certificate): array
    {
        return ['status' => 'PENDING_PROVIDER'];
    }
}
