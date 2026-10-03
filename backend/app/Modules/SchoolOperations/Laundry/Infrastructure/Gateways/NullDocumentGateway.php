<?php

namespace App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\DocumentProviderInterface;

/**
 * Dokumen (§31): label QR disimpan sebagai payload di `laundry_labels`
 * (dirender frontend). Gateway dokumen opsional untuk arsip berkas fisik.
 */
class NullDocumentGateway implements DocumentProviderInterface
{
    use GatewaySupport;

    public function store(array $payload): array
    {
        $provider = $this->delegate('App\Modules\Activity\Domain\Contracts\DocumentProviderInterface');

        if ($provider && method_exists($provider, 'store')) {
            try {
                return (array) $provider->store($payload);
            } catch (\Throwable $exception) {
                return ['stored' => false, 'reason' => $exception->getMessage()];
            }
        }

        return ['stored' => false, 'reason' => 'Penyedia dokumen tidak tersedia; payload label disimpan lokal.'];
    }

    public function available(): bool
    {
        return $this->delegate('App\Modules\Activity\Domain\Contracts\DocumentProviderInterface') !== null;
    }
}
