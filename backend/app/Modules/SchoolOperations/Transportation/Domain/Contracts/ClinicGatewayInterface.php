<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Klinik/UKS untuk insiden medis & kontak darurat (§48). */
interface ClinicGatewayInterface
{
    public function emergencyContacts(): array;

    public function available(): bool;
}
