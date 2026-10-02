<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\WorkflowGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Workflow & Approval Engine (§79).
 *
 * Khutbah, event keagamaan, dan pemesanan masjid memakai mesin persetujuan yang
 * sudah ada — modul ini tidak membangun mesin approval kedua.
 */
class WorkflowModuleGateway implements WorkflowGatewayInterface
{
    use GatewaySupport;

    protected function engine(): ?object
    {
        $configured = (string) config('religious.integrations.workflow_engine', '');
        if ($configured !== '' && class_exists($configured)) {
            try {
                return app($configured);
            } catch (\Throwable) {
            }
        }

        foreach ([
            'App\\Modules\\Workflow\\Application\\Services\\WorkflowEngineService',
            'App\\Modules\\Workflow\\Domain\\Contracts\\WorkflowServiceInterface',
        ] as $candidate) {
            if (interface_exists($candidate) && app()->bound($candidate)) {
                return $this->delegate($candidate);
            }
        }

        return null;
    }

    public function start(array $payload): array
    {
        if ($engine = $this->engine()) {
            try {
                foreach (['start', 'startApproval', 'startInstance'] as $method) {
                    if (method_exists($engine, $method)) {
                        $result = $engine->{$method}($payload);

                        return is_array($result)
                            ? array_merge(['started' => true], $result)
                            : ['started' => true, 'instance_id' => $result];
                    }
                }
            } catch (\Throwable $e) {
                return ['started' => false, 'error' => $e->getMessage()];
            }
        }

        return ['started' => false, 'reason' => $this->unavailableReason()];
    }

    public function approve(int|string $instanceId, array $payload = []): array
    {
        if ($engine = $this->engine()) {
            try {
                foreach (['approve', 'approveStep'] as $method) {
                    if (method_exists($engine, $method)) {
                        return ['approved' => true, 'result' => $engine->{$method}($instanceId, $payload)];
                    }
                }
            } catch (\Throwable $e) {
                return ['approved' => false, 'error' => $e->getMessage()];
            }
        }

        return ['approved' => false, 'reason' => $this->unavailableReason()];
    }

    public function reject(int|string $instanceId, string $reason): array
    {
        if ($engine = $this->engine()) {
            try {
                foreach (['reject'] as $method) {
                    if (method_exists($engine, $method)) {
                        return ['rejected' => true, 'result' => $engine->{$method}($instanceId, $reason)];
                    }
                }
            } catch (\Throwable $e) {
                return ['rejected' => false, 'error' => $e->getMessage()];
            }
        }

        return ['rejected' => false, 'reason' => $this->unavailableReason()];
    }

    public function status(int|string $instanceId): ?array
    {
        if ($engine = $this->engine()) {
            try {
                foreach (['status', 'state', 'find'] as $method) {
                    if (method_exists($engine, $method)) {
                        $result = $engine->{$method}($instanceId);
                        if (is_array($result)) {
                            return $result;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->engine() !== null
            || $this->has('workflow_instances')
            || $this->has('workflow_requests');
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Workflow Engine tidak tersedia pada instalasi ini.';
    }
}
