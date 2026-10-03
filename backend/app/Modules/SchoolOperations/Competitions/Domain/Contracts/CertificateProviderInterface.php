<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Contracts;

/** Certificate generation/verification belongs to the Certificate domain. */
interface CertificateProviderInterface extends ProviderAvailabilityInterface
{
    /** @return array{status:string, reference?:string|null, file_path?:string|null} */
    public function generate(array $certificate): array;
}
