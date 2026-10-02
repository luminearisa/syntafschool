<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\FinanceGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Finance (§52, §63). Modul ini TIDAK membuat ledger keuangan sendiri;
 * kotak donasi dan kontribusi qurban diteruskan ke modul keuangan.
 */
class FinancePostingGateway implements FinanceGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Hrd\\Core\\Domain\\Contracts\\FinancePostingProviderInterface')
            ?? $this->delegate('App\\Modules\\Hrd\\Domain\\Contracts\\FinanceProviderInterface')
            ?? $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\FinanceProviderInterface');
    }

    protected function post(string $kind, array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                foreach (['post', 'postCollection', 'record', 'createTransaction'] as $method) {
                    if (method_exists($provider, $method)) {
                        return array_merge(['posted' => true, 'kind' => $kind],
                            (array) $provider->{$method}($payload));
                    }
                }
            } catch (\Throwable $e) {
                return ['posted' => false, 'kind' => $kind, 'error' => $e->getMessage()];
            }
        }

        return [
            'posted' => false,
            'kind' => $kind,
            'pending_provider' => true,
            'reason' => $this->unavailableReason(),
        ];
    }

    public function postCollection(array $payload): array
    {
        return $this->post('COLLECTION', $payload);
    }

    public function postExpense(array $payload): array
    {
        return $this->post('EXPENSE', $payload);
    }

    public function budgetStatus(string $reference): ?array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'budgetStatus')) {
                    return (array) $provider->budgetStatus($reference);
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->provider() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available()
            ? null
            : 'Modul keuangan belum menautkan kontrak penerimaan; catatan disimpan sebagai referensi.';
    }
}
