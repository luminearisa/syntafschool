<?php

namespace App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\FinanceProviderInterface;

/**
 * Penerusan pendapatan/refund laundry ke Finance (§24).
 *
 * Laundry tidak membuat ledger akuntansi sendiri. Bila modul keuangan belum
 * terpasang, hasil dikembalikan PENDING agar dapat diposting kemudian —
 * pencatatan lokal (laundry_payments/refunds) tetap jadi jejak operasional.
 */
class FinancePostingGateway implements FinanceProviderInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\Modules\Activity\Domain\Contracts\FinanceProviderInterface')
            ?? $this->delegate('App\Modules\Asset\Domain\Contracts\FinanceProviderInterface')
            ?? $this->delegate('App\Modules\Hrd\Domain\Contracts\FinancePostingProviderInterface')
            ?? $this->delegate('App\Modules\Clinic\Domain\Contracts\FinanceProviderInterface');
    }

    public function postRevenue(array $payload): array
    {
        return $this->post('postRevenue', 'recordRevenue', $payload);
    }

    public function postRefund(array $payload): array
    {
        return $this->post('postRefund', 'recordRefund', $payload);
    }

    public function available(): bool
    {
        return $this->provider() !== null;
    }

    private function post(string $primary, string $fallback, array $payload): array
    {
        $provider = $this->provider();
        if (! $provider) {
            return [
                'posted' => false,
                'status' => 'PENDING',
                'reason' => 'Modul keuangan belum tersedia; posting laundry dicatat PENDING.',
            ];
        }

        foreach ([$primary, $fallback, 'postCost', 'post'] as $method) {
            if (method_exists($provider, $method)) {
                try {
                    $result = (array) $provider->{$method}($payload);

                    return ['posted' => true, 'status' => 'POSTED', 'reference' => $result['reference'] ?? null, 'response' => $result];
                } catch (\Throwable $exception) {
                    return ['posted' => false, 'status' => 'FAILED', 'reason' => $exception->getMessage()];
                }
            }
        }

        return ['posted' => false, 'status' => 'PENDING', 'reason' => 'Kontrak keuangan tersedia tanpa metode posting yang cocok.'];
    }
}
