<?php

namespace App\Modules\Bk\Infrastructure\Providers;

use App\Modules\Bk\Domain\Contracts\WorkflowProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Integrasi opsional dengan Workflow Engine ERP.
 *
 * Bila modul Workflow ada, BK dapat mengalirkan referral eksternal (mis. dari
 * manajemen) melalui definisi workflow sekolah. Bila tidak ada, seluruh alur BK
 * tetap berjalan — modul ini tidak boleh bergantung pada modul lain agar dapat
 * dipakai sekolah yang belum memasang Workflow.
 */
class WorkflowEngineProvider implements WorkflowProviderInterface
{
    public function available(): bool
    {
        return class_exists(\App\Modules\Workflow\Application\Services\WorkflowActionService::class)
            && app()->bound(\App\Modules\Workflow\Application\Services\WorkflowActionService::class);
    }

    public function start(string $definitionCode, array $payload, int $actorId): ?array
    {
        if (! $this->available()) {
            Log::info('BK: workflow tidak tersedia, referral diproses dengan alur internal.', [
                'definition' => $definitionCode,
            ]);

            return null;
        }

        try {
            $service = app(\App\Modules\Workflow\Application\Services\WorkflowActionService::class);

            if (! method_exists($service, 'startFromCode')) {
                return null;
            }

            $instance = $service->startFromCode($definitionCode, $payload, $actorId);

            return [
                'instance_id' => is_object($instance) ? $instance->id : $instance,
                'status' => is_object($instance) ? ($instance->status->value ?? null) : null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('BK: gagal memulai workflow.', [
                'definition' => $definitionCode,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
