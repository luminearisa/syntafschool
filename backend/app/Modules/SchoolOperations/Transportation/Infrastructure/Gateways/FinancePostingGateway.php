<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\FinanceGatewayInterface;

/**
 * Biaya operasional transportasi diteruskan ke Finance (§60/§61).
 * TMS tidak membuat ledger akuntansi sendiri: bila modul keuangan belum
 * terpasang, biaya tetap tercatat lokal dengan status PENDING agar dapat diposting.
 */
class FinancePostingGateway implements FinanceGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\Modules\Activity\Domain\Contracts\FinanceProviderInterface')
            ?? $this->delegate('App\Modules\Asset\Domain\Contracts\FinanceProviderInterface')
            ?? $this->delegate('App\Modules\Hrd\Domain\Contracts\FinancePostingProviderInterface')
            ?? $this->delegate('App\Modules\Clinic\Domain\Contracts\FinanceProviderInterface');
    }

    public function postCost(array $payload): array
    {
        $provider = $this->provider();
        if (! $provider) {
            return [
                'posted' => false,
                'status' => 'PENDING',
                'reason' => 'Modul keuangan belum tersedia; biaya dicatat lokal untuk diposting kemudian.',
            ];
        }

        foreach (['postCost', 'recordCost', 'post'] as $method) {
            if (method_exists($provider, $method)) {
                try {
                    $result = (array) $provider->{$method}($payload);

                    return ['posted' => true, 'status' => 'POSTED', 'reference' => $result['reference'] ?? null, 'response' => $result];
                } catch (\Throwable $exception) {
                    return ['posted' => false, 'status' => 'FAILED', 'reason' => $exception->getMessage()];
                }
            }
        }

        return ['posted' => false, 'status' => 'PENDING', 'reason' => 'Kontrak keuangan tersedia tetapi tanpa metode posting biaya.'];
    }

    public function postingEnabled(): bool
    {
        return $this->provider() !== null;
    }

    public function available(): bool
    {
        return $this->provider() !== null;
    }
}
