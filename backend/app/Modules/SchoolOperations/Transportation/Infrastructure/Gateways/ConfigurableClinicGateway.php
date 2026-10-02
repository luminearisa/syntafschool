<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\ClinicGatewayInterface;

class ConfigurableClinicGateway implements ClinicGatewayInterface
{
    use GatewaySupport;

    public function emergencyContacts(): array
    {
        $contacts = (array) config('transportation.emergency.contacts', []);
        $clinic = $this->delegate('App\Modules\Clinic\Domain\Contracts\ClinicSupportProviderInterface')
            ?? $this->delegate('App\Modules\UKS\Domain\Contracts\ClinicProviderInterface');

        if ($clinic && method_exists($clinic, 'emergencyContacts')) {
            try {
                $extra = (array) $clinic->emergencyContacts();
                $contacts = array_merge($contacts, $extra);
            } catch (\Throwable) {
            }
        }

        return array_values(array_filter($contacts, fn ($contact): bool => is_array($contact)));
    }

    public function available(): bool
    {
        return $this->delegate('App\Modules\Clinic\Domain\Contracts\ClinicSupportProviderInterface') !== null
            || $this->has('clinic_visits');
    }
}
