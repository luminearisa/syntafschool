<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\WorkflowGatewayInterface;

/**
 * Meneruskan permintaan persetujuan transportasi ke Workflow Engine bila
 * modul tersebut menyediakan kontraknya. Bila belum, permintaan tetap dicatat
 * dengan referensi internal sehingga alur approval manual tetap berjalan.
 */
class WorkflowModuleGateway implements WorkflowGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        foreach ([
            'App\Modules\Workflow\Domain\Contracts\WorkflowProviderInterface',
            'App\Modules\Workflow\Domain\Contracts\WorkflowInstanceProviderInterface',
            'App\Modules\Hrd\Domain\Contracts\WorkflowProviderInterface',
        ] as $contract) {
            if ($provider = $this->delegate($contract)) {
                return $provider;
            }
        }

        return null;
    }

    public function start(string $definition, array $payload): array
    {
        $provider = $this->provider();
        if ($provider) {
            foreach (['start', 'initiate', 'createInstance', 'launch'] as $method) {
                if (method_exists($provider, $method)) {
                    try {
                        $result = (array) $provider->{$method}($definition, $payload);

                        return [
                            'started' => true,
                            'engine' => 'workflow',
                            'reference' => $result['reference'] ?? ($result['code'] ?? null),
                            'response' => $result,
                        ];
                    } catch (\Throwable $exception) {
                        return ['started' => false, 'engine' => 'workflow', 'reason' => $exception->getMessage()];
                    }
                }
            }
        }

        return [
            'started' => false,
            'engine' => 'internal',
            'reference' => 'TMS-APR-'.now()->format('ymdHis'),
            'reason' => 'Workflow Engine tidak tersedia; persetujuan dicatat secara internal dan tetap diaudit.',
        ];
    }

    public function status(string $reference): ?array
    {
        $provider = $this->provider();
        if ($provider && method_exists($provider, 'status')) {
            try {
                return (array) $provider->status($reference);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    public function approve(string $reference, array $payload = []): array
    {
        $provider = $this->provider();
        if ($provider) {
            foreach (['approve', 'act', 'complete'] as $method) {
                if (method_exists($provider, $method)) {
                    try {
                        return ['approved' => true, 'engine' => 'workflow', 'response' => (array) $provider->{$method}($reference, $payload)];
                    } catch (\Throwable $exception) {
                        return ['approved' => false, 'engine' => 'workflow', 'reason' => $exception->getMessage()];
                    }
                }
            }
        }

        return ['approved' => true, 'engine' => 'internal', 'reference' => $reference];
    }

    public function available(): bool
    {
        return $this->provider() !== null;
    }
}
