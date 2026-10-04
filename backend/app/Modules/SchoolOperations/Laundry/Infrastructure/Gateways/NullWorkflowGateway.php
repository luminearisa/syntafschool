<?php

namespace App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\WorkflowProviderInterface;

/**
 * Workflow (§57): persetujuan override/kompensasi besar dapat dialirkan ke
 * Workflow Engine bila terpasang. Tanpa engine, modul laundry tetap jalan —
 * override cukup beralasan dan teraudit lokal.
 */
class NullWorkflowGateway implements WorkflowProviderInterface
{
    use GatewaySupport;

    public function requestApproval(array $request): array
    {
        $provider = $this->delegate('App\Modules\Workflow\Domain\Contracts\WorkflowRegistrarInterface')
            ?? $this->delegate('App\Modules\Transportation\Domain\Contracts\WorkflowGatewayInterface');

        if ($provider && method_exists($provider, 'requestApproval')) {
            try {
                return (array) $provider->requestApproval($request);
            } catch (\Throwable $exception) {
                return ['started' => false, 'reason' => $exception->getMessage()];
            }
        }

        return ['started' => false, 'reason' => 'Workflow engine tidak tersedia untuk modul laundry.'];
    }

    public function available(): bool
    {
        return $this->has('workflow_requests');
    }
}
